# JSON Output Contracts

## Envelope shape
```json
{
  "surface": "doctor|report|status",
  "version": 3,
  "ok": true,
  "driver": "mysql",
  "generated_at": "2026-01-01T00:00:00+00:00",
  "checks": [...],
  "gates": { "profile": "local", "verdict": "pass", "exit_code": 0 }
}
```

Reference: `src/Contracts/CommandJsonContract.php`.

## Evolution rules
- **Add** new top-level keys: bump `version`
- **Add** fields inside existing objects: keep `version`, consumers ignore unknown fields
- **Never remove** a key without a new `surface` version
- **Never change** a field's type (string → int)
- Agent payloads get a `compact` block with `verdict`, `severity`, `top_issue`, `next_action`, `exit_code`

## Output format mapping
| `--format` | Contract used |
|---|---|
| `table` | Human-readable, no contract |
| `json` | Full `CommandJsonContract` envelope |
| `agent` | Compact agent-friendly JSON |
| `compact-json` | JSON with nulls stripped |
