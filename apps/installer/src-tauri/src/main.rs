#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    fs,
    path::PathBuf,
    time::{SystemTime, UNIX_EPOCH},
};
use tauri::Manager;
fn validate(site: &str, local: bool) -> Result<url::Url, String> {
    let u = url::Url::parse(site).map_err(|_| "URL non valido")?;
    let loopback = matches!(u.host_str(), Some("localhost" | "127.0.0.1" | "[::1]"));
    if !u.username().is_empty()
        || u.password().is_some()
        || u.query().is_some()
        || u.fragment().is_some()
        || !(u.scheme() == "https" || (local && loopback && u.scheme() == "http"))
    {
        return Err(
            "Usa HTTPS, oppure loopback in modalità locale; niente credenziali o query.".into(),
        );
    }
    Ok(u)
}
#[tauri::command]
fn environment() -> String {
    let mut candidates: Vec<PathBuf> = vec![];
    if let Some(p) = std::env::var_os("LOCALAPPDATA") {
        candidates.push(PathBuf::from(p).join("Figma"))
    }
    candidates.extend(
        [
            "/Applications/Figma.app",
            "/usr/bin/figma-linux",
            "/opt/figma-linux",
            "/snap/bin/figma-linux",
        ]
        .map(PathBuf::from),
    );
    let found = candidates
        .iter()
        .filter(|p| p.exists())
        .map(|p| p.display().to_string())
        .collect::<Vec<_>>();
    format!(
        "{} / {} · {}",
        std::env::consts::OS,
        std::env::consts::ARCH,
        if found.is_empty() {
            "Figma non rilevato nei percorsi standard. Puoi importare il manifest dall’app installata.".into()
        } else {
            found.join(", ")
        }
    )
}
#[tauri::command]
async fn probe(site: String, local: bool) -> Result<String, String> {
    validate(&site, local)?;
    let mut builder = reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(12))
        .redirect(reqwest::redirect::Policy::none());
    if local {
        builder = builder.no_proxy();
    }
    let client = builder.build().map_err(|e| e.to_string())?;
    let mut response = client
        .get(format!(
            "{}/index.php?rest_route=/",
            site.trim_end_matches('/')
        ))
        .send()
        .await
        .map_err(|e| e.to_string())?
        .error_for_status()
        .map_err(|e| e.to_string())?;
    if response.status().is_redirection() {
        return Err("Il sito reindirizza: inserisci l’URL finale.".into());
    }
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|e| e.to_string())? {
        if bytes.len() + chunk.len() > 4_000_000 {
            return Err("Risposta REST troppo grande.".into());
        }
        bytes.extend_from_slice(&chunk)
    }
    let data: Value = serde_json::from_slice(&bytes)
        .map_err(|_| "Risposta non JSON: verifica URL e protezioni del sito")?;
    if !data["namespaces"]
        .as_array()
        .is_some_and(|v| v.iter().any(|n| n == "figma-elementor-multimodal/v1"))
    {
        return Err("WordPress risponde, ma il plugin FEM non è attivo.".into());
    }
    Ok("WordPress e REST FEM raggiungibili. Pairing e compatibilità del protocollo ancora da verificare in Figma.".into())
}
#[tauri::command]
fn prepare(app: tauri::AppHandle, site: String, local: bool) -> Result<String, String> {
    validate(&site, local)?;
    let root = app
        .path()
        .app_local_data_dir()
        .map_err(|e| e.to_string())?
        .join("plugins");
    package_at(&root, &site, local)
}
fn package_at(root: &std::path::Path, site: &str, local: bool) -> Result<String, String> {
    let u = validate(site, local)?;
    fs::create_dir_all(&root).map_err(|e| e.to_string())?;
    let key = format!("{:x}", Sha256::digest(site.as_bytes()));
    let target = root.join(&key[..16]);
    let backup = root.join(format!("{}-backup", &key[..16]));
    if !target.exists() && backup.exists() {
        fs::rename(&backup, &target).map_err(|e| e.to_string())?
    }
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| e.to_string())?
        .as_nanos();
    let staging = root.join(format!("staging-{stamp}"));
    fs::create_dir(&staging).map_err(|e| e.to_string())?;
    let network = if u.scheme() == "https" {
        json!({"allowedDomains":[u.origin().ascii_serialization()]})
    } else {
        json!({"allowedDomains":["none"],"devAllowedDomains":[u.origin().ascii_serialization()]})
    };
    let manifest = json!({"name":"Figma Elementor Multimodal","id":"fem-private-import","api":"1.0.0","editorType":["figma"],"main":"code.js","ui":"ui.html","documentAccess":"dynamic-page","networkAccess":network});
    let config = json!({"configVersion":1,"siteUrl":site,"environment":if local{"local"}else{"production"},"pluginVersion":"1.0.1"});
    let encoded = serde_json::to_string(&site)
        .map_err(|e| e.to_string())?
        .replace('<', "\\u003c")
        .replace('&', "\\u0026");
    let ui = include_str!("../../../../packages/figma-plugin/src/ui/index.html").replace(
        "</body>",
        &format!("<script>document.getElementById('base-url').value={encoded};</script></body>"),
    );
    for (name, body) in [
        ("manifest.json", manifest.to_string()),
        (
            "code.js",
            include_str!("../../../../packages/figma-plugin/src/code.js").to_string(),
        ),
        ("ui.html", ui),
        ("fem-setup.json", config.to_string()),
    ] {
        fs::write(staging.join(name), body).map_err(|e| e.to_string())?
    }
    if target.exists() {
        if backup.exists() {
            fs::rename(
                &backup,
                root.join(format!("{}-archive-{stamp}", &key[..16])),
            )
            .map_err(|e| e.to_string())?
        }
        fs::rename(&target, &backup).map_err(|e| e.to_string())?;
    }
    if let Err(error) = fs::rename(&staging, &target) {
        if !target.exists() && backup.exists() {
            let _ = fs::rename(&backup, &target);
        }
        return Err(error.to_string());
    }
    Ok(target.join("manifest.json").display().to_string())
}
#[tauri::command]
fn rollback(app: tauri::AppHandle, site: String, local: bool) -> Result<String, String> {
    validate(&site, local)?;
    let root = app
        .path()
        .app_local_data_dir()
        .map_err(|e| e.to_string())?
        .join("plugins");
    let key = format!("{:x}", Sha256::digest(site.as_bytes()));
    let target = root.join(&key[..16]);
    let backup = root.join(format!("{}-backup", &key[..16]));
    if !backup.is_dir() {
        return Err("Nessuna versione precedente disponibile per questo sito.".into());
    }
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| e.to_string())?
        .as_nanos();
    let archive = root.join(format!("{}-replaced-{stamp}", &key[..16]));
    if target.exists() {
        fs::rename(&target, &archive).map_err(|e| e.to_string())?
    }
    if let Err(e) = fs::rename(&backup, &target) {
        if archive.exists() {
            let _ = fs::rename(&archive, &target);
        }
        return Err(e.to_string());
    }
    Ok(target.join("manifest.json").display().to_string())
}
fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.get(1).map(String::as_str) == Some("--export") && args.len() == 4 {
        match package_at(std::path::Path::new(&args[3]), &args[2], true) {
            Ok(p) => println!("{p}"),
            Err(e) => {
                eprintln!("{e}");
                std::process::exit(1)
            }
        }
        return;
    }
    if args.get(1).map(String::as_str) == Some("--probe") && args.len() == 3 {
        match tauri::async_runtime::block_on(probe(args[2].clone(), true)) {
            Ok(p) => println!("{p}"),
            Err(e) => {
                eprintln!("{e}");
                std::process::exit(1)
            }
        }
        return;
    }
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![
            environment,
            probe,
            prepare,
            rollback
        ])
        .run(tauri::generate_context!())
        .expect("FEM Setup could not start")
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn urls() {
        assert!(validate("https://example.com/wp", false).is_ok());
        for s in [
            "http://localhost:8096",
            "http://127.0.0.1:8096",
            "http://[::1]:8096",
        ] {
            assert!(validate(s, true).is_ok());
            assert!(validate(s, false).is_err());
        }
        for s in [
            "http://example.com",
            "https://user:secret@example.com",
            "https://example.com/?x=1",
            "file:///tmp",
        ] {
            assert!(validate(s, true).is_err());
        }
    }
    #[test]
    fn package_update_preserves_previous() {
        let root = std::env::temp_dir().join(format!(
            "fem-test-{}",
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let first = package_at(&root, "http://127.0.0.1:8096", true).unwrap();
        let second = package_at(&root, "http://127.0.0.1:8096", true).unwrap();
        assert_eq!(first, second);
        let manifest: Value = serde_json::from_str(&fs::read_to_string(&first).unwrap()).unwrap();
        assert_eq!(
            manifest["networkAccess"]["devAllowedDomains"][0],
            "http://127.0.0.1:8096"
        );
        assert!(fs::read_dir(&root).unwrap().any(|p| p
            .unwrap()
            .file_name()
            .to_string_lossy()
            .ends_with("-backup")));
        let html =
            fs::read_to_string(std::path::Path::new(&first).with_file_name("ui.html")).unwrap();
        assert!(html.contains("value=\"http://127.0.0.1:8096\""));
    }
}
