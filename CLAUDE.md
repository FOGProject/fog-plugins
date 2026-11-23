# CLAUDE.md

The plugins that ship with FOG Project. `README.md` describes the layout, the
manifest, and the release. `.github/workflows/tests.yml` holds the checks.

## Documentation

User docs live in [FOGProject/fog-docs](https://github.com/FOGProject/fog-docs),
published at <https://docs.fogproject.org/>. Every pull request here ships with
its docs decision. Write it as a `Docs:` line in the pull request body:

- `Docs: FOGProject/fog-docs#<n>` names the fog-docs pull request for the change.
- `Docs: none — <reason>` is for a change that a user cannot see.
- `Docs: needed — <what>` is for when you cannot open the fog-docs pull request.

Where the docs go, under fog-docs `docs/`:

| What changed | fog-docs target |
|---|---|
| A plugin, added or removed | `management/web/plugins.md` |
| A plugin's settings or behavior | the plugin's own page under `management/web/` (for example `ldap.md`, `oidc.md`); create one if none exists |
| A bug fix that a user can see | a `kb/troubleshooting/` entry: the symptom text verbatim, the cause, the fixed-in version, and a workaround |

Follow fog-docs `CLAUDE.md` for the page format.
