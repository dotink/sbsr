<?php

namespace Deployer;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

//
// Extended Config
//

if (file_exists("deploy.yml")) {
	set("config", Yaml::parseFile("deploy.yml"));
} else {
	set("config", []);
}

//
// Options
//

option("force",    "F", InputOption::VALUE_NONE,     "Force deployment");
option("source",   "S", InputOption::VALUE_REQUIRED, "Set the database/sync source");
option("revision", "R", InputOption::VALUE_REQUIRED, "Set the branch/tag/commit/revision");
option("output",   "O", InputOption::VALUE_REQUIRED, "Output path for export commands");
option("input",    "I", InputOption::VALUE_REQUIRED, "Input path for import commands");

//
// Use hosts inventory if exists
//

if (file_exists("hosts.yml")) {
	inventory("hosts.yml");

} else {
	if (!empty(get("config")["stages"])) {
		$stages = get("config")["stages"];
	} else {
		$stages = ["dev", "uat", "prod"];
	}

	foreach ($stages as $stage) {
		localhost("local" . $stage)
			->stage($stage)
			->roles(["web", "files", "data"])
		;
	}
}

//
// Initialization
//

set("env", get("config")["env"] ?? []);

set("stage", function() {
	return input()->getArgument("stage");
});

set("options", function() {
	return array_merge(
		get("config")["options"] ?? array(),
		get("config")[parse("options-{{ stage }}")] ?? array()
	);
});

//
// Runtime
//

set("cwd", function() {
	return getcwd();
});

set("pid", function() {
	return getmypid();
});

set("self", function() {
	return $_SERVER["argv"][0]
		. (
			input()->hasOption("file")
				? sprintf(" --file=\"%s\" ", input()->getOption("file"))
				: NULL
		)
		. (
			input()->getOption("force")
				? sprintf(" -F ")
				: NULL
		)
	;
});

//
// Paths
//

set("cachePath",   "{{ cwd }}/cache");
set("sharesPath",  "{{ cwd }}/shares");
set("stagesPath",  "{{ cwd }}/stages");
set("releasePath", "{{ cwd }}/releases");

set("tmpPath", function() {
	return sys_get_temp_dir();
});

//
// VCS Settings
//

set("vcsType", function() {
	return parse(get("config")["vcs"]["type"] ?? "git");
});

set("vcsPath", function() {
	return parse(get("config")["vcs"]["path"] ?? NULL);
});

//
// DB Settings
//

set("dbType", function() {
	return parse(get("config")["db"]["type"] ?? "pgsql");
});

set("dbName", function() {
	return parse(get("config")["db"]["name"] ?? NULL);
});

set("dbHost", function() {
	return parse(get("config")["db"]["host"] ?? NULL);
});

set("dbUser", function() {
	return parse(get("config")["db"]["user"] ?? NULL);
});

set("dbRole", function() {
	return parse(get("config")["db"]["role"] ?? "web");
});

set("dbPass", function() {
	return parse(get("config")["db"]["pass"] ?? NULL);
});

//
// Programs
//

set("php", function() {
	return locateBinaryPath("php");
});

set("vcs", function() {
	switch(get("vcsType")) {
		case "git":
			return locateBinaryPath("git");

		default:
			writeln("<error>Unsupported VCS {{ vcsType }}</error>");
			exit(2);
	}
});

set("db", function() {
	switch(get("dbType")) {
		case "pgsql":
			return sprintf(
				"%s -U %s %s",
				locateBinaryPath("psql"),
				get("dbUser") ?: "postgres",
				get("dbHost") ? ("-h " . get("dbHost")) : NULL
			);

		case "mysql":
			return sprintf(
				"%s -u %s %s",
				locateBinaryPath("mysql"),
				get("dbUser") ?: "root",
				get("dbHost") ? ("-h " . get("dbHost")) : NULL
			);


		case "none":
			return FALSE;

		default:
			writeln("<error>Unsupported DB {{ dbType }}</error>");
			exit(2);
	}
});

set("db_dump", function() {
	switch(get("dbType")) {
		case "pgsql":
			return sprintf(
				"%s -U %s %s",
				locateBinaryPath("pg_dump"),
				get("dbUser") ?: "postgres",
				get("dbHost") ? ("-h " . get("dbHost")) : NULL
			);

		case "mysql":
			return sprintf(
				"%s -u %s %s --single-transaction",
				locateBinaryPath("mysqldump"),
				get("dbUser") ?: "root",
				get("dbHost") ? ("-h " . get("dbHost")) : NULL
			);

		case "none":
			return FALSE;
	}
});

//
// Context
//

set("branch", function() {
	switch(get("vcsType")) {
		case "git":
			return get("options")["branch"] ?? "master";
	}
});

set("revision", function() {
	return input()->getOption("revision") ?: get("branch");
});

set("commit", function() {
	switch (get("vcsType")) {
		case "git":
			$rev  = get("revision");
			$refs = run("{{ vcs }} ls-remote {{ vcsPath }}");

			foreach (explode("\n", $refs) as $ref) {
				list($commit, $head) = explode("\t", $ref);

				if ($head == "refs/heads/$rev") {
					$rev = $commit;
					break;
				}
			}

			return $rev;
	}
});

set("source", function() {
	if (input()->getOption("source")) {
		return input()->getOption("source");
	} elseif (!empty(get("options")["source"])) {
		return get("options")["source"];
	} else {
		return "prod";
	}
});

set("release", "{{ stage }}/{{ commit }}");

set("current", function($result = NULL) {
	foreach (roles("web") as $host) {
		if ($host->get("stage") == get("stage")) {
			on($host, function() use (&$result) {
				if (test("[ $(readlink {{ stagesPath }}/{{ stage }}) ]")) {
					$result = basename(run("readlink {{ stagesPath }}/{{ stage }}"));
				}
			});
		}
	}

	return $result;
});

/***************************************************************************************************
 ** Test Tasks
 **************************************************************************************************/

//
// Test whether or not the staged release already matches the revision.  We will only re-deploy
// the same revision if -F is provided to force rebuild, resync, etc.
//

task("test:release", function() {
	if (parse("{{ current }}") == parse("{{ commit }}") && !input()->getOption("force")) {
		writeln("<error>Commit {{ commit }} is already deployed on {{ stage }}, use -F to force.</error>");
		exit(2);
	}
})->onRoles("web");

//
// Test whether or not that revision requested is actually available in version control.
//

task("test:revision", function() {
	within("{{ cachePath }}", function() {
		switch(get("vcsType")) {
			case "git":
				run("{{ vcs }} fetch --all");

				if (run("{{ vcs }} cat-file -t {{ commit }}") != "commit") {
					writeln("<error>Invalid revision \"{{ revision }}\" specified</error>");
					exit(1);
				}

				break;
		}
	});
})->onRoles("files");

//
//
//

task("test:setup", function() {
	if (test("[ -e {{ releasePath }}/{{ stage }} ]") && !input()->getOption("force")) {
		writeln("<error>Stage {{ stage }} appears to already be set up, use -F to force.</error>");
		exit(2);
	}
})->onRoles("files");

/***************************************************************************************************
 ** Version Control Tasks
 **************************************************************************************************/

//
// Exports from version control to a release.
//

task("vcs:checkout", function() {
	if (!test("[ -e {{ releasePath }}/{{ release }} ]")) {
			run("mkdir -p {{ releasePath }}/{{ release }}");
	}

	within("{{ cachePath }}", function() {
		switch(get("vcsType")) {
			case "git":
				return run("{{ vcs }} archive {{ commit }} | tar -x --no-overwrite-dir --directory {{ releasePath }}/{{ release }}");
		}
	});
})->onRoles("files");

before("vcs:checkout", "test:revision");

//
//
//

task("vcs:diff", function() {
	within("{{ cachePath }}", function() {
		switch(get("vcsType")) {
			case "git":
				$diff = run("{{ vcs }} log {{ current }}...{{ commit }}");
				break;
		}

		if (!trim($diff)) {
			writeln("<info>There are no changes between the deployed and requested revision.</info>");
		} else {
			writeln($diff);
		}
	});
})->onRoles("files");

before("vcs:diff", "test:revision");

//
// Exports from version control to a stage's shares.
//

task("vcs:persist", function() {
	within("{{ cachePath }}", function() {
		$shares = array_unique(array_merge(
			get("config")["share"] ?? array(),
			get("config")[parse("share-{{ stage }}")] ?? array()
		));

		switch(get("vcsType")) {
			case "git":
				foreach ($shares as $share) {
					if (test("$({{ vcs }} cat-file -e {{ commit }}:$share)")) {
						run("{{ vcs }} archive {{ commit }} -- $share | tar -x --no-overwrite-dir --directory {{ sharesPath }}/{{ stage }}");
					}
				}
				break;
		}
	});
})->onRoles("files");

before("vcs:persist", "test:revision");

/***************************************************************************************************
 ** Database Tasks
 **************************************************************************************************/

//
//
//

task("db:drop", function() {
	switch(get("dbType")) {
		case "pgsql":
			if (test("{{ db }} -c \"\\q\" {{ stage }}_{{ dbName }}_new")) {
				return run("{{ db }} -c \"DROP DATABASE {{ stage }}_{{ dbName }}_new\" postgres");
			}
			break;

		case "mysql":
			if (test("{{ db }} -e \"exit\" {{ stage }}_{{ dbName }}_new")) {
				return run("{{ db }} -e \"DROP DATABASE {{ stage }}_{{ dbName }}_new\"");
			}
			break;

		case "none":
			break;
	}
})->onRoles("data");

//
//
//

task("db:create", function() {
	if (input()->getOption('force')) {
		invoke("db:drop");
	}

	switch(get("dbType")) {
		case "pgsql":
			if (!test("{{ db }} -c \"\\q\" {{ stage }}_{{ dbName }}_new")) {
				return run("{{ db }} -c \"CREATE DATABASE {{ stage }}_{{ dbName }}_new OWNER {{ dbRole }}\" postgres");
			}
			break;

		case "mysql":
			if (!test("{{ db }} -e \"exit\" {{ stage }}_{{ dbName }}_new")) {
				run("{{ db }} -e \"CREATE DATABASE {{ stage }}_{{ dbName }}_new\"");

				if (parse('{{ dbRole }}') != parse('{{ dbUser }}')) {
					run("{{ db }} -e \"GRANT ALL PRIVILEGES ON {{ stage }}_{{ dbName }}_new.* TO {{ dbRole }}\"");
				}
				
				return;
			}
			break;

		case "none":
			return;
	}

	writeln("<error>Database {{ stage }}_{{ dbName }}_new already exists, use -F to force.</error>");
	exit(2);
})->onRoles("data");

//
//
//

task("db:export", function() {
	if (!input()->hasOption("output")) {
		exit(2);
	}

	$file = input()->getOption("output");

	run("{{ db_dump }} {{ stage }}_{{ dbName }} > $file");

	//
	// If the file was not exported locally, it won"t exist and we"ll have to download
	// it then remove it from the remote server.
	//

	if (!file_exists($file)) {  // Check if file exists locally
		download($file, $file); // Download the file from the remote
		run("rm $file");        // Remove the file remotely
	}
})->onRoles("data");

//
//
//

task("db:import", function() {
	if (!input()->hasOption("input")) {
		writeln("<error>Unable to import database, no input sql specified, use -I.</error>");
		exit(2);
	}

	if (!file_exists($file = input()->getOption("input"))) {
		writeln("<error>Unable to import database, specified input sql does not exist.</error>");
		exit(2);
	}

	invoke("db:create");

	upload($file, $file);
	run("cat $file | {{ db }} {{ stage }}_{{ dbName }}_new");
	run("rm $file");

	//
	// If the file still exists then it was uploaded and imported remotely, so we still want
	// to remove it locally.
	//

	if (file_exists($file)) {
		runLocally("rm $file");
	}
})->onRoles("data");

//
//
//

task("db:rollout", function() {
	switch(get("dbType")) {
		case "pgsql":
			$has_new_db = test("{{ db }} -c \"\q\" {{ stage }}_{{ dbName }}_new");
			$has_old_db = test("{{ db }} -c \"\q\" {{ stage }}_{{ dbName }}_old");
			$has_cur_db = test("{{ db }} -c \"\q\" {{ stage }}_{{ dbName }}");

			if (!$has_new_db) {
				break;
			}

			if ($has_cur_db) {
				if ($has_old_db) {
					run("{{ db }} -c \"DROP DATABASE {{ stage }}_{{ dbName }}_old\" postgres");
				}

				run("{{ db }} -c \"ALTER DATABASE {{ stage }}_{{ dbName }} RENAME to {{ stage }}_{{ dbName }}_old\" postgres");
			}

			run("{{ db }} -c \"ALTER DATABASE {{ stage }}_{{ dbName }}_new RENAME to {{ stage }}_{{ dbName }}\" postgres");
			return TRUE;

		case "mysql":
			$new_db = "{{ stage }}_{{ dbName }}_new";
			$old_db = "{{ stage }}_{{ dbName }}_old";
			$cur_db = "{{ stage }}_{{ dbName }}";

			$has_new_db = test("{{ db }} -e \"exit\" $new_db");
			$has_old_db = test("{{ db }} -e \"exit\" $old_db");
			$has_cur_db = test("{{ db }} -e \"exit\" $cur_db");

			if (!$has_new_db) {
				break;
			}

			if ($has_cur_db) {
				if ($has_old_db) {
					run("{{ db }} -e \"DROP DATABASE $old_db\"");
				}

				run("{{ db }} -e \"CREATE DATABASE $old_db\"");
				run("{{ db }} -e \"GRANT ALL PRIVILEGES ON $old_db.* TO {{ dbRole }}\"");

				$rename_command  = "{{ db }} $cur_db -sNe 'show tables' | while read table; ";
				$rename_command .= "do {{ db }} -sNe \"RENAME TABLE $cur_db.\$table to $old_db.\$table\"; done";

				run($rename_command);
				run("{{ db }} -e \"DROP DATABASE $cur_db\"");
			}

			run("{{ db }} -e \"CREATE DATABASE $cur_db\"");
			run("{{ db }} -e \"GRANT ALL PRIVILEGES ON $cur_db.* TO {{ dbRole }}\"");

			$rename_command  = "{{ db }} $new_db -sNe 'show tables' | while read table; ";
			$rename_command .= "do {{ db }} -sNe \"RENAME TABLE $new_db.\$table to $cur_db.\$table\"; done";

			run($rename_command);
			run("{{ db }} -e \"DROP DATABASE $new_db\"");

			return TRUE;

		case "none":
			return FALSE;
	}

	//
	// TODO:  throw error that there is no new DB to roll out
	//
})->onRoles("data");

/***************************************************************************************************
 ** Deployment Tasks
 **************************************************************************************************/

task("setup", [
	"test:setup",
	"setup:cache",
	"setup:releases",
	"setup:shares",
	"setup:stages",
	"vcs:persist",
	"db:create",
	"db:rollout"
]);

task("setup:cache", function() {
	switch(get("vcsType")) {
		case "git":
			if (!test("[ -e {{ cachePath }}/HEAD ]")) {
				run("{{ vcs }} clone --mirror {{ vcsPath }} {{ cachePath }}");
			}
			break;
	}
})->onRoles("files");

task("setup:releases", function() {
	if (!test("[ -e {{ releasePath }}/{{ stage }} ]")) {
		run("mkdir -p {{ releasePath }}/{{ stage }}");
	}
})->onRoles("files");

task("setup:shares", function() {
	if (!test("[ -e {{ sharesPath }}/{{ stage }} ]")) {
		run("mkdir -p {{ sharesPath }}/{{ stage }}");
	}
})->onRoles("files");

task("setup:stages", function() {
	if (!test("[ -e {{ stagesPath }} ]")) {
		run("mkdir {{ stagesPath }}");
	}
})->onRoles("web");

/***************************************************************************************************
 ** Deployment Tasks
 **************************************************************************************************/

task("to", [
	"test:release",
	"vcs:checkout",
	"share",
	"build",
	"sync",
	"release"
]);

//
// The share task is responsible for re-linking shares to our release
//

task("share", function() {
	within("{{ releasePath }}/{{ release }}", function() {
		$shares_path  = parse("{{ sharesPath }}/{{ stage }}");
		$path_parts   = explode("/", parse("{{ releasePath }}/{{ release }}"));
		$link_root    = NULL;

		while (strpos($shares_path, implode("/", $path_parts) . "/") !== 0) {
			array_pop($path_parts);
			$link_root .= "../";
		}

		$link_root .= str_replace(implode("/", $path_parts) . "/", "", $shares_path);
		$link_paths = array_unique(array_merge(
			get("config")["share"] ?? array(),
			get("config")[parse("share-{{ stage }}")] ?? array()
		));

		foreach ($link_paths as $path) {
			$path = trim($path, '/');

			$extra_root = str_repeat('../', substr_count($path, '/'));

			run("rm -rf $path");
			run("ln -s $extra_root$link_root/$path $path");
		}
	});
})->onRoles("files");

//
// The build task is responsible for executing all the commands necessary to build our release.
//

task("build", function() {
	within("{{ releasePath }}/{{ release }}", function() {
		$commands = array_unique(array_merge(
			get("config")["build"] ?? array(),
			get("config")[parse("build-{{ stage }}")] ?? array()
		));

		foreach ($commands as $command) {
			run($command, ["tty" => TRUE]);
		}
	});
})->onRoles("files");

//
// The sync task is responsible for syncing share data from the source stage to the
// deployment stage.  Depending on whether or not the path is a file or folder in the
// source share, this will either `cp` or `rsync`.
//

task("sync", function() {
	if (get("stage") == get("source")) {
		return;
	}

	if (get("dbType") != "none") {
		runLocally("{{ self }} db:export -O {{ source }}_{{ dbName }}.sql {{ source }}");
		runLocally("{{ self }} db:import -I {{ source }}_{{ dbName }}.sql {{ stage }}");
	}

	within("{{ sharesPath }}", function() {
		$paths = array_unique(array_merge(
			get("config")["sync"] ?? array(),
			get("config")[parse("sync-{{ stage }}")] ?? array()
		));

		foreach ($paths as $path) {
			if (!dirname($path) != ".") {
				run("mkdir -p {{ stage }}/" . dirname($path));
			}

			if (is_dir(parse("{{ sharesPath }}/{{ source }}/$path"))) {
				run("rsync -rlpD {{ source }}/$path/ {{ stage }}/$path");

			} elseif (is_file(parse("{{ sharesPath }}/{{ source }}/$path"))) {
				run("cp {{ source }}/$path {{ stage }}/$path");

			} else {
				writeln("<error>Could not sync $path from {{ source }}, file or directory does not exist.</error>");
				exit(3);
			}
		}
	});
})->onRoles("files");

//
// The launch task is responsible for linking the webdocs for the deployment stage to the
// release.
//

task("release", function() {
	//
	// Run migrations
	//

	within("{{ releasePath }}/{{ release }}", function() {
		if (test("[ -x vendor/bin/phinx ]")) {
			if (get("stage") != get("source")) {
				run("{{ php }} vendor/bin/phinx migrate -e new", ["tty" => TRUE]);
			} else {
				run("{{ php }} vendor/bin/phinx migrate -e current", ["tty" => TRUE]);
			}
		}
	});

	//
	// Create a relative link to our release path, rollout our database, and link the new release
	// to the stage.
	//

	$release_path = parse("{{ releasePath }}/{{ release }}");
	$path_parts   = explode("/", parse("{{ stagesPath }}"));
	$link_root    = NULL;

	while (strpos($release_path, implode("/", $path_parts) . "/") !== 0) {
		array_pop($path_parts);
		$link_root .= "../";
	}

	$link_root .= str_replace(implode("/", $path_parts) . "/", "", $release_path);

	runLocally("{{ self }} db:rollout {{ stage }}");
	run("ln -fsn $link_root {{ stagesPath }}/{{ stage }}");

	//
	// Run release commands from the stage
	//

	within("{{ stagesPath }}/{{ stage }}", function() {
		$commands = array_unique(array_merge(
			get("config")["release"] ?? array(),
			get("config")[parse("release-{{ stage }}")] ?? array()
		));

		foreach ($commands as $command) {
			run($command, ["tty" => TRUE]);
		}
	});
})->onRoles("web");


task("prune", function() {
	within("{{ releasePath }}/{{ stage }}", function() {
		$list = explode("\n", run("ls -1c"));
		$live = basename(run("readlink {{ stagesPath }}/{{ stage }}"));

		unset($list[array_search($live, $list)]);

		foreach ($list as $release) {
			if ($release) {
				writeln("Removing release: $release");
				run("rm -rf {{ releasePath }}/{{ stage }}/$release");
			}
		}
	});
})->onRoles("files");
