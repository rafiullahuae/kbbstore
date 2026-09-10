# KBB Storefront

A Laravel port of K-Beauty Bliss's WordPress storefront (kbeautybliss.com), owned by Rafi.

## Start here

**[`KBB-Master-Plan.md`](./KBB-Master-Plan.md)** is the actual project history and status —
what's shipped, what's still open, every non-obvious bug and why it happened, every decision
and why it was made. If you're a new Claude session or a developer picking this up cold, read
that file first. This README is just orientation to the repo itself.

## How this project actually ships

This is **not** a git-deploy setup. The live server is shared hosting with no shell access, so
updates reach it as signed zip packages through a custom in-app updater (Store → Core Updates
in the admin panel), not `git pull`. This repository is the durable source-of-truth underneath
that — the exact code at any point in time, diffable, independent of any chat session — while
the zip packages remain how changes actually get installed on the live site.

Every successfully-applied patch since 2.60.41 is also archived directly on the live server
itself and downloadable from the same admin screen — see the Master Plan's Phase 12 notes and
risk register (▲ entries) for the reasoning behind that.

## Structure

Standard Laravel layout. A few things worth knowing:

- `public/` here only contains `build/` (compiled Vite assets) — `public/index.php` and the
  rest of the real web root live separately on the server, one level outside this app root.
- `storage/catalog/products.json` is real source data (the product catalogue import), not a
  runtime cache file — kept in the repo deliberately.
- `env.staging.txt` is a credential-free template for the staging environment's `.env`, safe
  to keep in the repo — every value in it is blank.
- `kbb-finish.php` and `run-composer.php` are deployment tooling for the shared-hosting
  environment (no shell access means no `composer install` the normal way) — see their own
  doc comments.

## What's excluded

`vendor/`, `node_modules/`, `.env` (real credentials), and runtime-generated files under
`storage/framework/` and `storage/logs/` — standard Laravel `.gitignore` conventions.
