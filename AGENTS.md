# AGENTS.md

Instruksi kerja untuk semua AI coding agent (Claude Code, Antigravity, OpenCode, Cursor, dan lainnya) ada di **`CLAUDE.md`**. Baca file itu sepenuhnya sebelum mengerjakan apa pun, lalu lanjutkan ke `Implementasi_Plan.md` §6 dan `TASKS.md`.

Ringkasan aturan yang paling sering dilanggar:
1. Setiap daftar wajib paging, pencarian, filter, dan sort lewat `HasListing` + `DataTable`/`PagedList`. Test kontrak akan merah bila tidak.
2. Parameter bisnis dibaca dari `setting()`, bukan konstanta.
3. Berhenti dan bertanya bila spesifikasi bertentangan atau perlu mengubah skema/API/permission di luar task.
4. Hanya data dummy. Jangan pernah melemahkan kontrol keamanan.
