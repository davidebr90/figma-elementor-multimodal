# FEM runtime preflight

Questo controllo prepara il test reale WordPress + Elementor senza modificare il sistema host.

Da PowerShell, nella root del worktree:

```powershell
./tools/runtime/preflight.ps1
```

Il controllo verifica:

- Docker CLI e daemon attivo;
- presenza e validità di `compose.fem-test.yaml`;
- presenza del pacchetto Elementor autorizzato in `.runtime/elementor`;
- presenza di `.runtime/elementor/elementor.php`;
- validità finale del compose.

Il pacchetto Elementor non viene scaricato automaticamente e non deve essere committato nel repository.
