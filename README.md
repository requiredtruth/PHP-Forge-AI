# PHP Forge AI

A complete neural PHP code laboratory in one distributable PHP file. It downloads examples from the official English PHP manual repository, extracts program listings, trains a CPU-sized character RNN from random weights, checkpoints resumable progress, and forges new functions or classes through neural drafting plus a syntax-guided structural layer.

No Composer packages. No database. No remote AI API.

## Run

Requirements: PHP 8.1+, `ext-curl`, `ext-json`, and a writable project directory.

```bash
./install.sh
./run.sh
```

Open `http://127.0.0.1:8080`.

The browser provides:

- clean start, download-only, initialize-only, and reset actions
- visible download/training status and progress
- seconds-per-request, sequences-per-request, and additional-step controls
- resumable checkpoints and stop-after-checkpoint behavior
- steps, smoothed loss, characters/second, corpus size, and model state
- function/class selection, specification, optional exact name, temperature, and top-k
- generated candidate, parser result, blocked-call check, neural score, and raw neural draft

## Why the hybrid forge exists

A tiny character RNN can learn local PHP character and syntax patterns, but it cannot guarantee semantic correctness or complete source units. PHP Forge AI therefore uses the RNN for drafting and learned-likeness scoring while a bounded structural builder creates the complete candidate. PHP's tokenizer/parser validates syntax without executing the result. Generated code still requires human review and behavior tests.

## Runtime files

Corpus, checkpoints, locks, and operation status live in `php_forge_ai_data/`, which is ignored by Git. Set `PFA_DATA_DIR` to use another writable runtime directory:

```bash
PFA_DATA_DIR=/srv/php-forge-ai ./run.sh
```

## Training source and network behavior

The application downloads a bounded allowlist of XML manual pages from the official [`php/doc-en`](https://github.com/php/doc-en) repository over verified HTTPS. It extracts PHP program listings and records source attribution. No source pages, model checkpoints, prompts, or generated code are uploaded by the application.

## Safety and limitations

- Run locally by default; `run.sh` binds to `127.0.0.1` unless explicitly overridden.
- Browser mutations require same-session CSRF tokens.
- Writes are atomic and download/training operations are locked.
- Generated candidates are parsed but never executed by the application.
- A small blocked-call scan is not a security proof or sandbox.
- The character RNN is educational and CPU-sized, not a transformer or production coding model.
- Manual downloads require network access; previously downloaded corpus and checkpoints remain local.

## Differentiation

This is not a web wrapper around a hosted model and not a generic code-snippet form. Its narrower purpose is to make corpus acquisition, local neural training, checkpointing, visible progress, constrained synthesis, and parser validation inspectable inside one PHP 8.1 file.

## Support

Public donation addresses and the confirmed-transaction request process are in [SUPPORT.md](SUPPORT.md). Confirm the asset and network before sending.

## License

Application code is MIT licensed; see [LICENSE](LICENSE). Downloaded PHP manual material retains its own copyright and licensing terms documented by the PHP project.



## Standard launcher

`./run.sh` is the normal entry point. It runs `./install.sh` automatically when setup is missing, then opens the PySide6 control panel with live output and actions for the demo, tests, repair, and stop. Use `./cli.sh` for CLI-only operation.
