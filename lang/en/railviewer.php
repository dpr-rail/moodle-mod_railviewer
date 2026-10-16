<?php
$string['pluginname'] = 'Railviewer';
$string['railviewername'] = 'Name';
$string['modulename'] = 'Railviewer';
$string['modulenameplural'] = 'Railviewers';
$string['modulename_help'] = 'This module is an activity module where users can view route videos having been assigned to them by admins';
$string['railviewer:addinstance'] = 'Add new railviewer activity plugin instance';
$string['railviewer:view'] = 'View railviewer content';

$string['route'] = 'Route';
$string['requiredplaycounts'] = 'Required Playthroughs';
$string['completedplaycounts'] = 'Completed Playthroughs';
$string['remainingplaycounts'] = 'Remaining';
$string['action'] = 'Action';
$string['start'] = 'Start';
$string['continue'] = 'Continue';
$string['completed'] = 'Completed';
$string['viewlist'] = 'Routes to be completed';
$string['startview'] = 'Start route';
$string['progress'] = 'Progress';

$string['pluginadministration'] = 'Railviewer Administration';

$string['yourroutes'] = 'Your assigned routes';
$string['yourroutes_du'] = 'Your assigned routes';
$string['yourroutes_sie'] = 'Your assigned routes';
$string['noroutesassigned'] = 'No routes assigned to you.';
$string['section'] = 'Section';
$string['nosectionsdefined'] = 'No sections defined';
$string['notlinkedtoactivity'] = 'Not currently linked to an activity, or not visible to you';

$string['invalidassignmentid'] = 'Invalid assignment ID.';
$string['invalidcmid'] = 'Invalid course module ID.';

$string['videotitle'] = 'Watch video';
$string['nosectionsfound'] = 'No sections found.';
$string['allplaythroughscompleted'] = 'All playthroughs for this route have been completed.';
$string['allplaythroughscompleted_du'] = 'Congratulations! You have completed all playthroughs for this route.';
$string['allplaythroughscompleted_sie'] = 'Congratulations! You have completed all playthroughs for this route.';
$string['returntripready'] = 'All playthroughs completed. The return trip "__ROUTE__" is ready.';
$string['returntripready_du'] = 'Congratulations! You have completed all playthroughs. The return trip "__ROUTE__" is ready.';
$string['returntripready_sie'] = 'Congratulations! You have completed all playthroughs. The return trip "__ROUTE__" is ready.';
$string['watch_again'] = '🔁 Watch this direction again';
$string['watch_return_trip'] = '🔀 Watch the return trip';
$string['back_to_overview'] = '📋 Back to overview';
$string['playcountcompleted'] = 'Playthrough completed! Starting playthrough #{$a}...';
$string['confirm_leave_section'] = 'Your progress in this section may not be fully saved yet. Switch anyway?';
$string['settingsinfo'] = 'This plugin has no settings of its own. API credentials, addressing and overlay defaults are configured via <a href="{$a}">Railviewer route management</a>.';

// Route map widget.
$string['streckenmap'] = 'Route map';
$string['back_to_listview'] = 'Back to list view';
$string['back_to_assignments'] = 'Back to assignment overview';
$string['adminview_notice'] = 'Admin view: you are viewing the route map of {$a} (read-only, not playable).';
$string['streckenmap_legend_heading'] = 'Legend';
$string['status_none'] = 'No video assigned';
$string['status_offen'] = 'Open';
$string['status_in_bearbeitung'] = 'In progress';
$string['status_erledigt'] = 'Completed';
$string['show_tasklist'] = 'Show task list';
$string['hide_tasklist'] = 'Hide task list';
$string['progress_tooltip_template'] = '{views} of {required} playthroughs – {remaining} left';
$string['progress_tooltip_done_template'] = '✓ completed ({views}/{required})';
$string['history_heading'] = 'History';
$string['history_empty'] = 'No completed playthroughs yet.';
$string['history_completed_on'] = 'Completed on';
$string['show_history'] = 'Show history';
$string['hide_history'] = 'Hide history';
$string['completed_routes_heading'] = 'Completed routes ({$a})';
$string['rewatch'] = 'Watch again';
$string['roundtrip_label'] = 'Outbound & return';
$string['roundtrip_combined_progress'] = '{$a->completed} / {$a->required} combined';
$string['diag_no_activity_exists'] = '(Admin note: no course has a Railviewer activity that could show this route - not even a generic one. Please create one.)';
$string['diag_no_course_access'] = '(Admin note: no course access to "{$a}" - neither enrolled nor otherwise authorised.)';
$string['diag_not_visible'] = '(Admin note: the activity in "{$a}" is not visible to you - hidden or restricted by availability conditions.)';
$string['diag_no_capability'] = '(Admin note: missing mod/railviewer:view capability in "{$a}".)';
$string['reviewmode_notice'] = 'This route has already been fully completed - you are watching it again for review, without this requiring any further playthroughs.';
$string['playpause'] = 'Play/Pause';
$string['mutetoggle'] = 'Mute/Unmute';
$string['seekahead_blocked'] = 'Fast-forwarding is only possible once you have regularly reached this point in the video. Rewinding to review is always possible.';
$string['playbackspeed'] = 'Playback speed';
$string['speed_unsupported_ignite'] = 'Playback speed is not supported for ignite.video videos.';
$string['overview_and_history'] = 'Overview & history';
$string['restricttoroute'] = 'Restrict to one route';
$string['restricttoroute_label'] = 'Use this activity only for a single route selected below';
$string['restricttoroute_help'] = 'By default (unchecked), this activity shows every user ALL routes assigned to them - no matter where or how they were assigned. This is the normal case: you do not need to create a separate activity for every assigned route.

Only check this if this activity should show exactly one specific route, e.g. to embed it into a particular course topic.';
$string['invalidroute'] = 'Invalid route.';
$string['editactivitysettings'] = 'Edit activity';

