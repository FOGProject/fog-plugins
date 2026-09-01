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
  src/                        ALL PHP, laid out exactly like core's src/
    Items/                    models
    Managers/                 managers (schema migrations live here)
    Pages/                    the management page
    Hooks/                    hook registrations
    Events/                   event listeners (optional)
    Reports/                  reports (optional)
    Tasks/                    scheduled background work (optional)
    Util/                     plain helpers (optional)
  js/                         fog.<node>.<sub>.js
```

**`<plugin>/src/<Bucket>/<Class>.php` declares
`FOG\Plugins\<Segment>\<Bucket>\<Class>`**, with the file name equal to the
class name and `strtolower(<Segment>)` equal to the plugin's directory name.
`tests/plugin-layout.test.php` gates both. The autoloader derives the path from
the class name, so a file in the wrong place does not load and a class in the
wrong bucket is never registered. See
[ADR 0035](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/adr/0035-a-plugin-is-laid-out-like-core.md);
the pre-1.6 `class/ pages/ hooks/` layout is refused with a message naming the
plugin.

`Pages`, `Hooks`, `Events`, `Reports` and `Tasks` are the buckets core
enumerates. Everything else is autoload-only, so the name is yours — these are
core's, and using them means someone who knows core knows your plugin.

A `src/Tasks/<Class>.php` declares a class extending `PluginTask` with an
`$interval` and a `run()`, and the `FOGPluginRunner` daemon runs it while the
plugin is active and installed — a plugin never ships a systemd unit of its
own. It runs as the web user rather than root, and `run()` has to be
idempotent. See `helloworld/src/Tasks/HelloWorldHeartbeat.php` for a worked
example and ADR 0010 in `FOGProject/fogproject` for why it is shaped this way.

Requires FOG **1.6.0-beta.3350** or newer. The runner itself landed in
beta.3349, but `PluginTask::log()` — which the example uses — landed one build
later; on beta.3349 the task is discovered and then fails with an undefined
method, which the runner catches and logs rather than crashing on.

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
| `oidc` | 1.6.0 | Sign in through an OpenID Connect provider; the reference for a plugin that adds a route rather than a resource |
| `ou` | 1.6.0 | Predefine Active Directory OUs and associate them with hosts |
| `pushbullet` | 1.6.0 | Pushbullet notifications |
| `slack` | 1.6.0 | Slack API integration |
| `subnetgroup` | 1.6.0 | Assign hosts to groups automatically by IP subnet |
| `taskstateedit` | 1.6.0 | Edit and create task states |
| `tasktypeedit` | 1.6.0 | Edit and create task types |
| `windowskey` | 1.6.0 | Associate Windows product keys with images |
| `wolbroadcast` | 1.6.0 | Wake-on-LAN across separate broadcast addresses |

`site` used to be listed here and is not a plugin any more. Per
[ADR 0006](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/adr/0006-site-object-scope-boundary.md)
the object-scope boundary is default-allow, so **with no listener the boundary
does not exist** — which made a plugin an admin could uninstall, or a
half-failed upgrade could remove, the wrong home for it. Site moved into
`FOGProject/fogproject` proper; nothing here replaces it.

## Writing a plugin

The full guide is
[`docs/plugin-development.md`](https://github.com/FOGProject/fogproject/blob/working-1.6/docs/plugin-development.md)
in the FOG repository — manifest fields, the `schema()` migration contract,
hook events, the permission registry, and the gotchas that cost the most time.
`helloworld/` here is the working skeleton it describes, and §11b of that guide
is the port table for a plugin still on the pre-1.6 layout.

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
