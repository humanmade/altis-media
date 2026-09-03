# Media Library Infinite Scrolling

WordPress 7.1 enables infinite scrolling in the Media Library grid view by default. Altis switches it back off.

Infinite scrolling is a poor experience for accessibility. Keyboard and screen reader users lose a stable end of the
list to navigate to, and on the large media libraries typical of Altis sites the grid grows without bound as content
loads beneath the user. With infinite scrolling disabled the grid shows a "Load More" button instead, so loading more
attachments is an explicit, focusable action.

This applies everywhere the media grid appears: the Media Library screen, the media modal in the editor, and the
[Global Media Library](./global-media-library.md).

## The per-user setting

WordPress 7.1 also adds an "Infinite Scrolling" checkbox under Users > Profile. WordPress gives the
`media_library_infinite_scrolling` filter precedence over that preference, so while the Altis override is active the
checkbox would have no effect. Altis hides it rather than showing a control that does nothing.

A user's stored preference is left untouched while the checkbox is hidden, so it is still there if the override is
later switched off.

## Disabling the Altis override

The override can be switched off via the Altis config:

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "disable-media-library-infinite-scroll": false
                }
            }
        }
    }
}
```

Setting this to `false` **removes the Altis override**. It does not turn infinite scrolling on. Behaviour returns to
whatever WordPress does by default, including the per-user Users > Profile setting, which becomes visible again.
If a future version of WordPress changes its default, disabling the override follows that change.
