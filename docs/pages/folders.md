---
slug: /managed-folders
---

# Managed folders

A few special apps have specialized folders.

- video players will create and use the "video" folder at the root of your filesystem.
- book/comic readers will create and use the "books" folder at the root of your filesystem.
- games will create a folder under "games/<game_name>". Each will use their own folder for saves, mods, etc

## Storage

Your data is stored on docker volumes, you won't be able to access them directly. This is by design.

On the host the `/var/lib/docker/volumes` directory can be moved to an external drive if that would give it more storage for example.
This is advanced usage, only do this if you know what you are doing. If you move that directory, do copy all your old files to the new location, otherwise your apps will lose their data.

It is ideal to do it before you start using this software.