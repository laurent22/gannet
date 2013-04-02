# Gannet - A database migration tool in PHP

This is a database migration tool that manages and runs a set of migration scripts.

For more flexibility, the library does not force you to write your migration scripts in any particular language. It simply ensures that they are being executed in the correct order and that the database is up to date. What the individual script files do is up to you.

Although it is in PHP it can in fact work with any database or server as long as the PHP command line is installed.

# How does it work?

The library requires a migration table (called `dbmigrations` by default) which contains the current version of the database. The version is assumed to be `0` by default.

You then add the migration scripts (usually SQL files) to the `scripts` folder. Each file should be named like the target version (eg. "1.1.sql", "1.2.0.sql", etc.).

When run, the Gannet looks in this folder and run any script that is above the current version. Once done, the migration table is updated with the new version number.

# Usage

* Clone the repository in any folder on your server.

* Create the migration table using the script in `config/migration_table`

* Copy `config/config.sample.toml` to `config/config.toml` and set the database connection settings. Also you will most likely need to update the `command.sql` section with your own command line. See the [config file comments](config/config.sample.toml) for more information.

* Add the migration scripts to the `scripts` folder. They should be named like the target database version - eg. "1.1.sql", "1.1.0.sql", etc. Version numbers can have up to three digits (x.x.x).

* You can then run the migration tool - `php gannet.php`

# Command line parameters

Optionally, the script can take a path to a configuration file as a parameter. This allows having different configuration for different environments. For example:

	php gannet.php config/config.local.toml
	php gannet.php config/config.live.toml

# Advanced usage

## Running any script file

Usually, the database upgrade will be done by running SQL files. For more complex migrations, you can also run any other script file. To do so, add a new `[command.xxx]` section to `config.toml`, where `xxx` is the file extension (for example "go", "php", "sh", etc.). You then define the command line to run this script file using the `command` parameter. For example, below is how you would run PHP or Go scripts:

	[commands.php]
	command = "/opt/lampp/bin/php-cgi \"{{file}}\" 2>&1"
	success_code = 0
	
	[commands.go]
	command = "go run \"{{file}}\" 2>&1"
	success_code = 0

## Upgrading a database using more than one migration file

If more than one script file must be run for a given database upgrade, you can split the migration into several files. For example, you might have a SQL file that add a table to the database, then a PHP script that will dynamically populate the database. In that case, you can simply split the upgrade into two versioned files: 1.1.0.sql and 1.1.1.sql. The library will automatically run them in the correct order.

## Error handling

By default, the migration process will stop whenever an error happens in any of the migration scripts. To specify the error code for a given command line, use the `success_code` parameter. See [config.sample.toml](config/config.sample.toml) for more details.