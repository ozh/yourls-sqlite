# SQLite Driver for YOURLS 1.7.1+

## What

This is a custom DB layer that allows to use YOURLS with PDO + SQLite

This is experimental, mostly to show how it should be done, ie without [hacking core file](https://github.com/YOURLS/YOURLS/wiki/Dont-Hack-Core) - see [YOURLS issue #1337](https://github.com/YOURLS/YOURLS/issues/1337) (1337, for real!).

This requires YOURLS **1.7.1** (if the official 1.7.1 [release](https://github.com/YOURLS/YOURLS/releases) isn't ready yet, that means you'll need to install [current master](https://github.com/YOURLS/YOURLS/archive/master.zip)) and may completely break with next release

If you notice something that doesn't work as expected, please open an issue with details on how to reproduce and wait for someone to submit a pull request to fix. If you can both submit the issue and the pull request, you're the boss!

## How

* Drop these files in `/user/`, next to your `config.php` (this is *not* a plugin)
* Load YOURLS: the first time, it will create a fresh SQlite DB in that same `user` directory
* Have fun

## FAQ

##### *Doesn't work!*
See above

##### *Will this break my existing install that uses MySQL?*
Nope! All the data stored in MySQL is untouched (you can test this driver with no SQL server running to be sure) and when you're done, simply delete (or rename) the `db.php` file and you'll get all your original data back from MySQL

## License

Do whatever the hell you want with it
