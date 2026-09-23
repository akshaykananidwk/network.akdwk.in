# Installer payload

`build-windows-pack.sh` writes `akconnect-agent.exe` and `wintun.dll` here
before building `akconnect-setup.exe`, which embeds them.

The two placeholder files beside this one are committed so the tree builds on a
machine that has not run the pack script. They are not usable software, and the
installer refuses to run when it finds one rather than installing nonsense —
see `payload.go`.
