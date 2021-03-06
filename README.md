# Fabrica Collaborative Editing
FCE is a plugin developed by [Yes We Work](http://yeswework.com/) to make WordPress more Wiki-like by allowing more than one person to edit the same Post, Page, or Custom Post Type at the same time. When edits conflict, FCE helps users to view, compare, and merge changes before saving.

## Who is this for?
At its most general, Fabrica Collaborative Editing is a plugin for any multi-user WordPress site where people work together on content.

FCE was developed to allow WikiTribune’s journalists and contributors to collaborate accountably – yet quickly and with minimal friction and distraction – on the editing of time-critical news stories, so it is also suitable for other WordPress-based publications whose editorial model embraces collaborative journalism and community involvement.

FCE can also be helpful in other ways on multi-user WP sites:

- offer remote help to new users: FCE lets two users access the same Post’s edit screen, so a more experienced user can see what the novice sees, help them find issues, and talk them through specific steps
- undertake admin tasks without interrupting: a colleague might wish to check a Post’s edit screen while someone else is working on it, and can do so without either forcibly ‘taking over’ from them, or having to wait for them to finish

NB. FCE’s collaborative editing model is not Google Docs -like; users do not see what each other are typing in real time, but can work and concentrate independently. This also allows for a greater scale of collaboration. Furthermore, each users saved edits create Post Revisions, which makes editing more accountable and less subject to error or misuse.

## Features and functionality
- Overrides WordPress’s default single-user lock which prevents multiple users from editing the same post at the same time. This involves a combination of delicately modifying (Heartbeat API) transmissions between client and server, to stop the lock mechanism being triggered, and hooking into some UI filters to disable warnings elsewhere.
- With the lock disabled, users could continually overwrite each other’s changes, so to prevent this, we intercept save to check whether other user(s) have suggested changes since the current user started editing.
- If there are other changes causing edit conflicts, we cache the current user’s changes (with the Transients API), abort the regular save operation, and present a conflict-resolution interface.
- The conflict resolution interface shows comparisons (diffs) of all the conflicting fields (title, body, textual Advanced Custom Fields) highlighting the differences. For this we use the standard revision diffs in WordPress, but we additionally implemented Visual diffs where stylistic HTML features are rendered as they would be on the front-end – essential for writers who don’t understand HTML to be able to evaluate differences, and to make it much easier to check changes in elements like images.
- Revising the conflicts, users then edit their version to take into account the other users’, then save again. If there have been yet more edits, conflict resolution will kick in again; if not, the changes are committed and a new revision is created.
- We also provide a simple mechanism for users to abandon their edit in case they consider the other user’s changes to be more suitable than their suggestion.
- Collaborative editing can be enabled or disabled per (custom) post type (via the plugin’s settings screen).

## Installation
Download the zip file and install in WordPress via Plugins > Add New > Upload Plugin, then activate the plugin.

## Changelog
### 0.1.0 (2018-05-29)
- Published as an open-source project on Github, under the MIT license

## Who made this
FCE was originally designed and coded by [Yes We Work](http://yeswework.com/) in conjunction with WikiTribune. [Fabrica](https://fabri.ca/) is a series of tools designed to improve WordPress for content creators and developers.
