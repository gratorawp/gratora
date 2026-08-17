# WordPress.org listing assets

They live in `.wordpress-org/`, which the deploy workflow syncs wholesale into
SVN's `assets/` directory. Only artwork belongs there: anything else in that
folder is published to a world-readable URL, which is why this note sits here
instead. Nothing in it ships inside the plugin.

Names are fixed by the plugin directory:

| File | Size | What it is |
| --- | --- | --- |
| `icon-256x256.png` | 256x256 | The icon in search results and the plugin card. |
| `banner-772x250.png` | 772x250 | The header on the plugin page. |
| `banner-1544x500.png` | 1544x500 | The same banner for retina displays. |
| `screenshot-1.png` … | any, shown at 772 wide | In the order readme.txt's `== Screenshots ==` captions them. |

The captions live in `readme.txt` and are matched by position, so a screenshot
added or reordered here has to move there too, or every caption after it
describes the wrong image.
