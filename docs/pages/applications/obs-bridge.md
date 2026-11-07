---
slug: /obs-bridge
---

# OBS bridge

This is specialized software mostly meant for streamers.

## Basic usage

In the application deploy gui, you can set:
- PUBLISH_PASSWORD: The password used to *send* a stream to the bridge.
- READ_PASSWORD: The password used to *receive* a stream from the bridge.


In your obs, you need to configure the upstream (the one sending the stream) as:
- Server:rtmp://<server>:1935
- Stream Key: bridge?user=publisher&pass=<PUBLISH_PASSWORD> 

On the receiving end, you need to use this as a media source:
- rtmp://<server>:1935/bridge?user=reader&pass=<READ_PASSWORD>

<server> is the current domain of your server, the same one you see in your browser when reading this.

Publish and read passwords are set when you start up the application, and can be changed with a restart.

In your sidebar, Obs bridge shows you a preview of what is being sent to you. 

## Advanced usage

You can provide a full custom configuration file for mediamtx.
No other advanced configuration is provided, if you need a more complicated setup refer to the official documentation:
https://mediamtx.org/docs/references/configuration-file