# FOG Plugins

The plugins that ship with [FOG Project](https://github.com/FOGProject/fogproject).
One repository, one release, per-plugin versions in each manifest.

This is the source of truth for the 15 bundled plugins. The FOG build pins a
revision of this repository and lays its contents into
`packages/web/lib/plugins/` in the tarball.

## Why this repository exists

Until FOG 1.6 a plugin had no existence independent of the FOG source tree.
`configureHttpd()` does `rm -rf $webdirdest` on every upgrade and nothing
rescued `lib/plugins/`, so a plugin survived an upgrade only by shipping in
`FOGProject/fogproject`. That is why no third party has ever shipped a FOG
plugin.

FOG 1.6 changed that. See
[ADR 0009](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/adr/0009-plugins-become-installable-artifacts.md)
for the decision and its reasoning:

- **Two plugin roots.** `lib/plugins/` inside the web tree holds these bundled
  plugins and is re-laid on every upgrade. `/opt/fog/plugins` sits under
  `$fogprogramdir`, is never touched by the installer, and is where
  third-party plugins live. A third-party directory that collides with a
  bundled name is refused and logged, never silently shadowed.
- **A real manifest** — `version`, `fog_min`/`fog_max`, `author`, `homepage`,
  `requires[]`. A plugin outside its declared FOG range cannot be activated,
  and an installed one deactivates itself on upgrade with a message rather
  than fataling a page.
- **Install from the UI** — upload a `.tar.gz`, check the checksum and parsed
  manifest, then confirm. Off unless an admin sets
  `FOG_PLUGIN_UI_INSTALL_ENABLED` *and* a root has made the external root
  writable with `bin/fog-plugin-uploads.sh enable`.

## Layout

Each directory is one plugin, laid out exactly as it appears under
`lib/plugins/` on a running server:

```
<plugin>/
  config/plugin.config.php    the manifest
  class/                      model + manager (schema migrations live here)
  pages/                      the management page
  hooks/                      hook registrations
  js/                         fog.<node>.<sub>.js
```

`index.php` at the root is the directory-listing guard, and is shipped with
the rest.

## What is here

| Plugin | Version | What it does |
|---|---|---|
| `capone` | 1.6.0 | Automation plugin — image a host straight from PXE without registering it |
| `helloworld` | 1.6.0 | Skeleton example: config, model, manager, page, hooks, JS |
| `ldap` | 1.6.0 | Authenticate FOG users against an LDAP or AD directory |
| `location` | 1.6.0 | Serve images from the storage node nearest a host — multi-site installs |
| `ntfy` | 1.6.0 | Notifications via ntfy.sh or a self-hosted ntfy server |
| `ou` | 1.6.0 | Predefine Active Directory OUs and associate them with hosts |
| `persistentgroups` | 1.6.0 | Keep group membership across re-registration |
| `pushbullet` | 1.6.0 | Pushbullet notifications |
| `site` | 1.6.0 | Group hosts into sites; limit which hosts a user can see |
| `slack` | 1.6.0 | Slack API integration |
| `subnetgroup` | 1.6.0 | Assign hosts to groups automatically by IP subnet |
| `taskstateedit` | 1.6.0 | Edit and create task states |
| `tasktypeedit` | 1.6.0 | Edit and create task types |
| `windowskey` | 1.6.0 | Associate Windows product keys with images |
| `wolbroadcast` | 1.6.0 | Wake-on-LAN across separate broadcast addresses |

`site` is a special case. Per
[ADR 0006](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/adr/0006-site-object-scope-boundary.md)
the object-scope boundary is default-allow, so **with no listener the boundary
does not exist**. `site` is therefore always shipped and must not become
something an admin can uninstall or a half-failed upgrade can remove. It lives
here as source; it is not a candidate for the external plugin root.

## Writing a plugin

The full guide is
[`docs/plugin-development.md`](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/plugin-development.md)
in the FOG repository — manifest fields, the `schema()` migration contract,
hook events, the permission registry, and the gotchas that cost the most time.
`helloworld/` here is the working skeleton it describes.

Third-party plugins belong in your own repository. Ship a `.tar.gz` holding
one directory named for the plugin with `config/plugin.config.php` inside it,
and an admin can install it from Plugin Management or clone it straight into
`/opt/fog/plugins`.

## History

Filtered out of `FOGProject/fogproject` with `git-filter-repo`, keeping both
`packages/web/lib/plugins/` and the `packages/web/management/plugins/` path it
was moved from on 2014-05-20. The tip tree is byte-for-byte identical to the
source subdirectory it was taken from, and per-file history crosses that move —
`git log --follow capone/config/plugin.config.php` reaches back to 2014.

`git subtree split` does not work on the FOG history: the repository has two
root commits, and subtree cannot map parents across the second one, so it
emits whole-repository commits unrewritten.

## Licence

GPLv3, the same as FOG. See [LICENSE](LICENSE).
