---
name: changelog
description: Draft or update the CHANGELOG.md entry for the next release of this package, in the project's Keep a Changelog style. Use when asked to write release notes, generate or update the changelog, prepare a release, or choose the next version.
argument-hint: "[version]"
---

# Changelog

Draft the next `CHANGELOG.md` entry from the changes since the last release tag. The style and content rules are in `CONTRIBUTING.md` under **Changelog**. Read that section first and follow it. This skill only adds the procedure.

If the user gave a version (`$ARGUMENTS`), use it. Otherwise, propose one in step 3.

## 1. Gather the changes

- Find the last release tag with `git tag --sort=-v:refname | head -1`.
- List the commits since that tag with `git log --oneline --no-merges <tag>..HEAD`.
- Read the diff, not just the commit messages: `git diff --stat <tag>`, then `git diff <tag> -- src composer.json` and any other file that changed. Check `git status` for uncommitted work that is part of the release.
- Read the existing `## [Unreleased]` section, if there is one. Keep its entries and add what's missing.

Commit messages are a starting point, not a source. They can name a feature wrong, and a one-line change labeled `fix:` can change behavior for existing users.

## 2. Classify each change

Compare each change with the behavior at the last tag, not with commits in between.

- Leave out anything that was added and then changed or removed since the last tag. It never shipped.
- Treat a changed default value as a behavior change. Put it under **Changed** with a `**How:**` sub-bullet for the users it affects. Then find every place that sets the default (the config file, `?? <default>` fallbacks in `src/`, and the tests) and every place that documents it (config docblocks, `README.md`, and exception messages). Report any that disagree instead of writing around them.
- Leave out changes that don't affect users, such as CI, lint fixes, and test refactors, unless they change what users can rely on, such as the supported PHP or Laravel versions.

## 3. Choose the version

- **Major:** a breaking change to the public API, config keys, or supported framework versions.
- **Minor:** new features, or a changed default that alters behavior for existing users.
- **Patch:** bug fixes and documentation only.

Say which version the changes call for and why. Ask before using a lower version than that.

## 4. Write the entry

- Use `## [X.Y.Z] - YYYY-MM-DD` with today's date when releasing, or `## [Unreleased]` when the user isn't releasing yet.
- Follow the **Format** rules in `CONTRIBUTING.md`, and use the entries that it names as the examples for a major and a minor release.
- Name the config keys, environment variables, classes, and methods that users would search for.

## 5. Update the links

- Add the link reference at the bottom of `CHANGELOG.md`, newest first, in the same form as the existing ones: `[X.Y.Z]: <repository URL>/compare/<previous tag>...X.Y.Z`. Use the real tag names from `git tag`, since some older tags have two components (ex. `4.2`).
- If `README.md` links to a release section, update the anchor when the heading or date changes. The anchor includes the date (ex. `#500---2026-10-31` for `## [5.0.0] - 2026-10-31`).

## 6. Check and report

- If `src/` or `composer.json` changed, run `composer prerelease`.
- Don't commit, tag, or push unless the user asks. Tags are `X.Y.Z` with no `v` prefix.
- Report the version and why, anything that you left out and why, and any inconsistencies that you found.
