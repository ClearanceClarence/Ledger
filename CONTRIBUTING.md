# Contributing to Ledger

Thanks for your interest. Ledger is a small project run by one person, so this guide is short. Read it before opening an issue or PR.

## Before you file anything

**Search first.** Most ideas have come up before. The issue tracker is the canonical record of "did someone already ask this?"

**Confirm it's a bug, not a config issue.** Ledger ships sane defaults but every host is different. Before filing a bug, try:

1. Reading the relevant section of the README
2. Checking the [CHANGELOG](CHANGELOG.md) for breaking changes between your version and current
3. Reproducing on a clean install if you upgraded from an older version
4. Checking `logs/` for errors that explain what's actually failing

## Reporting bugs

Use the bug report template. The important fields:

- **Version** — `LEDGER_VERSION` from `index.php`, or read it from the app footer
- **Environment** — PHP version, MySQL/MariaDB version, OS, web server (Apache/nginx/whatever)
- **Steps to reproduce** — the exact sequence of clicks or input. "It doesn't work" isn't a bug report.
- **What you expected** vs **what happened**
- **Error message** if any, from the browser console, the PHP error log, or `logs/`

Screenshots help. So do exports of small example schemas that trigger the bug.

## Requesting features

Use the feature request template. Be explicit about what problem you're solving, not just what you want added. "Add export to JSON" is a request; "I need to feed query results into another tool that only reads JSON, and copy-pasting the CSV doesn't preserve types" is a problem with a solution shape.

I'll be honest about whether the feature fits the project. Some things I'll cheerfully say no to — Ledger is a focused tool, not phpMyAdmin v2. Common no-fits:

- Anything that requires Composer or npm — the zero-dependency posture is intentional
- PostgreSQL support — out of scope, this is a MySQL/MariaDB tool
- Anything that bloats the install footprint significantly
- Wrapping it in a different framework

## Submitting code

For small fixes (typos, obvious bugs, broken docs): just open a PR. No need to ask first.

For anything else: **open an issue first** and let me say whether the direction makes sense before you spend time on the code. I'd rather have a 30-second "yes/no" conversation than reject a 200-line PR.

Once you have the green light:

1. Fork the repo and create a branch from `main`
2. Make focused commits with descriptive messages (see [git commit message guide](https://chris.beams.io/posts/git-commit/) if you want a reference)
3. Keep the diff scoped — one feature or fix per PR
4. Test it on your own install before opening the PR. Include a note in the PR description about what you tested.
5. Open the PR against `main`

## Code style

There's no formal style guide. Match the surrounding code. A few specifics:

- **PHP**: 4-space indent, opening braces on the same line for methods, snake_case for variables and function names where it's already established, PascalCase for class names. Type hints on method signatures.
- **JavaScript**: 2-space indent in templates, 4-space in standalone JS files, vanilla JS only (no jQuery, no ES module bundlers beyond what loads natively in browsers), `const` and `let` over `var`.
- **CSS**: 4-space indent, follow the existing theme variable system in `themes/dark-industrial/style.css`.
- **SQL**: keywords UPPERCASE, identifiers backtick-quoted in generated SQL, single quotes for string literals.

For PHP, run `php -l <file>` before submitting to catch syntax errors. There's no CI yet, so this is on you.

## What I won't merge

- Anything that adds a Composer dependency without a strong reason
- Anything that adds an npm dependency
- Code that introduces a build step
- Refactors that don't change behavior but rearrange a lot of files
- Features behind feature flags that aren't enabled by default — if a feature isn't ready to be on, it isn't ready to be merged
- PRs that combine multiple unrelated changes

## What I want help with

- **Real-world testing.** Try Ledger on hosting setups I haven't tested (different PHP versions, MariaDB variants, shared hosting, weird Apache configs) and report what breaks.
- **Documentation.** Especially edge cases in setup that the README handwaves.
- **Translations** — once there's a string extraction system in place. Not yet.
- **Security review.** If you read PHP for a living and want to scan the auth, CSRF, and SQL paths, I'd genuinely appreciate it.

## License

By contributing code, you agree that your contributions will be licensed under the same MIT license as the rest of the project.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). Be a decent person.

## Questions

If something in this document is unclear, open a discussion or an issue tagged with `question`. I'd rather explain something than have someone get frustrated and leave.
