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

Caddy labels:

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

##### OIDC

The core image contains an authelia service, which can be used for servers that only accept OIDC authorization. It is configured to use the same users as the LDAP service, so you can use the same credentials.

To gain access:
1. Request: write oidc/requests/<client_id> on the localhost_global_env volume, with its redirect URIs one per line.
2. Wait: the core watcher registers the client and restarts Authelia once. Poll for oidc/secrets/<client_id> to appear.
3. Configure: set the OIDC issuer to https://<DOMAIN>/auth, with the client ID, the secret from the file, the same redirect URIs, and scopes openid profile email groups.

This is a little convoluted, but we also can't do any static configuration with it as all applications are installed dynamically.