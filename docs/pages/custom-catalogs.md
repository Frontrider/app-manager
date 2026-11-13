# Custom catalogs

The application has an "upload catalog" button, which can be used to add more application options.
The catalog only contains what is needed to deploy the applications, but not the applications themselves.


### Structure

the zip file's root contains 2 folders:
- singleton
- standard

Each folder has their categories as subfolders. These are arbitrary, used to group them on the sidebar.

Singleton applications can only have one of them deployed at once. 
These are things like file browsers, where having multiple versions of them could become a problem, or services with very similar purposes that are configured the same way.
Eg: book readers, or video players that all get the same paths on the server.

### Deployments

inside the categories, we have the applications.
Each of them are a docker compose configuration, using caddy's labels to manage external access.

More information can be found here:

https://docs.docker.com/compose/

Setup-managed configuration must be reapplied by the application container on
every start, including Docker restarts. A completed one-shot setup dependency is
not sufficient: Docker does not rerun it when restarting the application.
Catalog defaults and environment values override UI edits to managed settings.
Preserve application data, accounts, bootstrap credentials, and unrelated settings.
Configuration failures must stop startup rather than run with stale settings.

The **Application activity** screen shows catalog applications that are running,
starting, or stopping, and flags failed or paused containers. It refreshes every
three seconds and hides successfully completed setup containers. Start, refresh,
and stop operations remain visible while Docker commands are in progress.
Administrators can open an application's logs from this screen.

When all of an application's services are stopped, administrators can also
delete its Compose-managed volumes after confirming the irreversible data loss.
External volumes and bind-mounted host data are preserved.

### App metadata

Each application can include an optional `metadata.json` file next to its Compose
configuration:

```json
{
  "website": "https://example.org/",
  "caddy_path": "/example/",
  "protected": false,
  "dependencies": [
    {
      "name": "calibre-web",
      "category": "book-reader",
      "required": true
    }
  ]
}
```

- `website` is the official site link shown on the application card.
- `caddy_path` is the same-domain path exposed through Caddy; running applications link to it.
- `protected` marks applications that must not be shut down when the server is overloaded.
- `dependencies` lists applications that must be started before this one. `name` matches the app folder or display name, `category` selects the catalog category, and `required` defaults to `true` and controls whether a missing dependency blocks startup. Dependencies are started recursively, and dependencies that are already running are left alone.

### Caddy labels

https://github.com/lucaslorentz/caddy-docker-proxy

### Data volume
The main thing to watch out for, is that if the user needs to see your files, then it must be on the `localhost_data` volume.
This is mounted to `/data` in the file browser container.
The user accessing it is set to 1000:1000, that is what you need to use if you interact with it.

ONLY use this for files that the user needs to see, otherwise use a different volume for your data, to make it easier to manage it and avoid accidental deletion.


### Authorization
##### LDAP

The main way to authorize users is through the LLDAP service.
Prefer to use the currently logged in user, but there is a service user that can also be used if absolutely necessary.
The password is in the `localhost_global_env` volume, in the `lldap_service_password` file. The name is `service`.

Use this with the current user whenever possible, as it is the simplest solution for a deployment at this scale.

##### Forward auth

When the application does not support ldap, caddy's forward auth can be used.
You won't get permission management, but access is still restricted to the lldap_admin group.

An example for the caddy labels to use:
```yaml
      caddy.7_handle.0_forward_auth: '127.0.0.1:9091'
      caddy.7_handle.0_forward_auth.uri: '/auth/api/authz/forward-auth'
      caddy.7_handle.0_forward_auth.copy_headers: 'Remote-User Remote-Groups Remote-Name Remote-Email'
```

LDAP should be preferred, but this can be used when nothing else is available or compatible.