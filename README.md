[![](https://images.microbadger.com/badges/image/cscheide/image-watcher.svg)](http://microbadger.com/images/cscheide/image-watcher)
[![](https://images.microbadger.com/badges/version/cscheide/image-watcher.svg)](http://microbadger.com/images/cscheide/image-watcher)

# What is image-watcher?

The image-watcher is a simple docker container process that will monitor and aggregate multiple image URLs into a security camera console like image grid. It will then distribute this image according to user preferences. It currently supports up to 9 images and utilizes Seafile as a document repository.


# How to use this image

Using with an environment variable file. 

```console
$ docker run --name some-image-watcher --env-file ./env.list -d cscheide/image-watcher
```

There is a sample.env file contained within the distribution for you to reference if needed.


## Running via [`docker-compose`](https://github.com/docker/compose)

Example `docker-compose.yml` for `image-watcher`:

```yaml


xxxx


```

# Environment Variables

This container utilizes environment variables for configuration. The following shows the required and optional environment variables

## Required Variables

The following environment variables are required in order for the container to work properly:

- `CAM_IMAGE_URL1` : At least a single URL to an image file or image feed
- `CAM_SEAFILE_URL` : The url of your seafile server in the form of `http(s)://your.seafile.server:port`
- `CAM_SEAFILE_APITOKEN` : The access [API token](http://manual.seafile.com/develop/web_api.html#quick-start) for Seafile
- `CAM_SEAFILE_LIBRARY_ID` : The library ID of the Seafile library you would like to store the images on

## Optional Variables

In addition, the container supports the optional configurations:

- `CAM_SEAFILE_DIRECTORY` : The directory in which to store resulting images
- `CAM_SEAFILE_ENCRYPTION_KEY` : The Seafile encryption key to utilize for encrypted libraries
- `CAM_ENCRYPT_TIMEOUT_MINS` : How often to renew the encryption authorization in Seafile (Default: 30)
- `CAM_PHP_TIMEZONE` : The timezone to utilize for logging purposes. Utilizes [PHP timezone formats](http://php.net/manual/en/timezones.php).
- `CAM_LOG_DEBUG` : Setting this to `true` will result in more verbose logging
- `CAM_MAX_WIDTH` : The maximum width in pixels of the composited image (Default: 1280)
- `CAM_OUTPUT_QUALITY` : The JPG compression quality of the composited image (Default: 80)
- `CAM_INTERVAL_TIME_SECS` : The interval of time in seconds between image creation (Default: 60)
- `CAM_CLEAN_TIME_MINS` : The interval of time in minutes to regularly check for older images to be removed (Default: 60)
- `CAM_RETENTION_TIME_HOURS` : The period in hours over which to retain images (Default: 24)

# Feedback / Contribution

## Issues

If you have any problems with or questions about this image, please post a [GitHub issue](https://github.com/crscheid/image-watcher/issues).

## Contributing

This started out as a hobby project for an particular issue that I had. However, anyone is invited to contribute new features, fixes, or updates, large or small; I will be happy to receive any ideas on how to make this better.

Before you start to code, please try to discuss your plans through a [GitHub issue](https://github.com/crscheid/image-watcher/issues), especially for more ambitious contributions. This gives other contributors a chance to point you in the right direction, give you feedback on your design, and help you find out if someone else is working on the same thing.




