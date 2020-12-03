# Troubleshooting

## Some Image URLs Are Broken With Tachyon Enabled

Prior to WordPress 5.3.1 any image files uploaded with a file name like `example-300x300.jpg` were allowed and unmodified. In order to better support automatic modification of image URLs for features like responsive images the dimensions are removed.

In order to work properly and for performance reasons Tachyon requires all images to not have dimensions as their suffix.

Altis provides a migration command to rename legacy images and make the necessary database updates.

```
wp media rename-images [--network] [--sites-page=<int>] [--search-replace] [--tables=<tables>] [--include-columns=<columns>]
```

- `--network` if present will run the process for all sites on the network.
- `--sites-page` allows you to change the current page of sites. 100 sites will be processed at a time so if you have more you will need to run the command again for each page of sites.
- `--search-replace` if present will perform a database search and replace process for the updated image names.
- `--tables` defaults to `wp*_posts, wp*_postmeta` and accepts a comma separated list of tables you wish to run the update on. Wildcards are supported.
- `--include-columns` defaults to `post_content, meta_value` and accepts a comma separated list of database table columns you want to perform the updates on.

Note that this command will take a long time to run when updating the database as well. Additionally the more tables and columns you update the slower this command will be.
