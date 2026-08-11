# Project Context

## Product identity

- The current product name is **TKA Cerdas**.
- The product is a TKA try-out and learning platform. Previous product branding is obsolete.
- Never introduce user-facing copy, AI prompts, seed data, documentation, examples, or test fixtures using the previous brand.
- Use `TKA`, `TKA Cerdas`, `Try Out TKA`, or `Simulasi TKA` according to context.

## Infrastructure identity

- Use `tka-cerdas` for Compose projects, container images, deployment directories, database names, backup paths, and other technical identifiers.
- Preserve production data when changing infrastructure identifiers by backing up the database before deployment.

## Validation

- Run `npm run build` after changing TypeScript or React files.
- Run the most specific relevant PHPUnit test first, then `php artisan test --compact` before finalizing broad changes.
- Preserve existing production data and backward compatibility unless the user explicitly requests a migration.
