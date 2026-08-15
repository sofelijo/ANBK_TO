# Project Context

## Product identity

- The current product name is **TOA**, short for **Try Out Adaptif**.
- The product is an adaptive try-out and learning platform. Previous product branding is obsolete.
- Never introduce user-facing copy, AI prompts, seed data, documentation, examples, or test fixtures using the previous brand.
- Use `TOA`, `Try Out Adaptif`, or `Simulasi Adaptif` according to context.
- Use the horn loudspeaker mark in `ApplicationLogo` as the product icon.

## Infrastructure identity

- Use `toa` for new Compose projects, container images, deployment directories, database names, backup paths, and other technical identifiers.
- Keep documented legacy aliases and explicit Docker volume names only where required for backward compatibility with existing deployments.
- Preserve production data when changing infrastructure identifiers by backing up the database before deployment.

## Validation

- Run `npm run build` after changing TypeScript or React files.
- Run the most specific relevant PHPUnit test first, then `php artisan test --compact` before finalizing broad changes.
- Preserve existing production data and backward compatibility unless the user explicitly requests a migration.
