<?php

/*
 * kore-ui/calendar interface texts (English).
 *
 * No view carries hard-coded text (tests/Ui/TextosEnInglesTest.php enforces it), and
 * every key here also exists in lang/es/calendar.php with the same placeholders
 * (:count, :date…): tests/Ui/IdiomasTest.php checks it.
 *
 * To change one without touching the package: `php artisan vendor:publish --tag=kore-calendar-lang`
 * and edit the copy in lang/vendor/kore-calendar/, or set it in `translations` in the config.
 */

return [
    'all_day' => 'All day',
    'more' => '+:count more',
    'more_label' => 'Show all events on :date (:count more)',
    'week' => 'Wk',
    'week_number' => 'Week :number',
    'no_events' => 'No events in this period',
    'close' => 'Close',
    'events_of' => 'Events on :date',
    'previous' => 'Previous',
    'next' => 'Next',
    'today' => 'Today',
    'views' => 'View',
    'view_month' => 'Month',
    'view_week' => 'Week',
    'view_day' => 'Day',
    'view_list' => 'Agenda',
    'view_resource-week' => 'Resources · week',
    'view_resource-day' => 'Resources · day',
    'view_timeline-day' => 'Timeline · day',
    'view_timeline-week' => 'Timeline · week',
    'view_timeline-month' => 'Timeline · month',
    'resources' => 'Resources',
    'kbd_help' => 'Press Space to pick up and move with the keyboard. Use the arrow keys to change the day or time, Shift and the arrow keys to change the duration, Enter to drop and Escape to cancel.',
    'kbd_grabbed' => 'Picked up.',
    'kbd_cancelled' => 'Canceled.',
    'toggle_group' => 'Show or hide :group',

    // An event title in quotes, in the texts that name it inside a list.
    'event_quoted' => '“:title”',

    // Interaction: rejection reasons appear in a toast; successful moves and
    // resizes are only announced through aria-live (with `undoable()` also in a toast).
    'move_rejected' => 'Could not move the event',
    'resize_rejected' => 'Could not change the event duration',
    'not_editable' => 'This event cannot be edited',
    'resource_locked' => 'This event cannot be moved to another resource',
    'overlap_rejected' => 'Overlaps with “:title”',
    'out_of_range' => 'Outside the allowed range',
    'outside_business_hours' => 'Outside business hours',
    'outside_business_hours_resource' => 'Outside business hours for :resource',
    'event_not_found' => 'The event is no longer available',
    'resource_not_found' => 'The target resource does not exist',
    'invalid_duration' => 'The duration cannot be zero or negative',
    'invalid_change' => 'The change is not valid',
    'overlap_rejected_hidden' => 'Overlaps another event',
    'persist_failed' => 'The change could not be saved',
    'event_moved' => '“:title” moved to :date',
    'event_resized' => '“:title” now ends on :date',
    'event_resized_start' => '“:title” now starts on :date',

    // What anyone sees who has not implemented a `KoreCalendar` hook yet (persistMove,
    // persistResize, createEvent, updateEvent, deleteEvent): it is the rejection reason.
    'hook_missing' => 'This calendar does not save that change yet: implement :hook() in :class.',

    // `->highlightConflicts()`: the screen reader text of an event that overlaps others.
    'conflict' => 'Conflicts with :with',
    // With more than three conflicts only three titles are named; the rest are counted (:titles is the quoted list).
    'conflict_more' => ':titles and :count more',

    // Undo (`->undoable()`): a toast with the action after a move or resize.
    'undo' => 'Undo',
    'undo_done' => 'Change undone',
    'undo_failed' => 'The change could not be undone',

    // Create, edit and delete with the form (`->eventForm()`). Failures appear
    // in an error toast with the reason; successes, in a success one.
    'create_rejected' => 'Could not create the event',
    'update_rejected' => 'Could not save the event',
    'delete_rejected' => 'Could not delete the event',
    'event_created' => '“:title” created',
    'event_updated' => '“:title” saved',
    'event_deleted' => '“:title” deleted',

    // The event form. "All day" is `all_day`, the one at the top.
    'form_create' => 'New event',
    'form_edit' => 'Edit event',
    'form_title' => 'Title',
    'form_start_date' => 'Start date',
    'form_start_time' => 'Start time',
    'form_end_date' => 'End date',
    'form_end_time' => 'End time',
    'form_resource' => 'Resource',
    'form_color' => 'Color',
    'color_custom' => 'Custom',
    'form_pick_date' => 'Pick a date',
    'form_pick_time' => 'Pick a time',
    'form_no_resource' => 'No resource',
    'form_no_color' => 'No color',
    'form_save' => 'Save',
    'form_cancel' => 'Cancel',
    'form_delete' => 'Delete',
    'form_delete_confirm' => 'Delete “:title”?',
    'form_delete_description' => 'This action cannot be undone.',
    'form_required' => 'This field is required',
    'form_invalid' => 'The value is not valid',
    'form_end_before_start' => 'The end cannot be before the start',

    // Recurring series: the dialog that asks what a change to one occurrence
    // applies to. "Cancel" is `form_cancel`.
    'scope_move' => 'Move a recurring event',
    'scope_resize' => 'Change the duration of a recurring event',
    'scope_edit' => 'Edit a recurring event',
    'scope_delete' => 'Delete a recurring event',
    'scope_question' => '“:title” is a recurring event. Which events in the series do you want to change?',
    'scope_this' => 'Only this event',
    'scope_following' => 'This and following events',
    'scope_all' => 'All events',

    // The colors in the picker (kore-ui tokens).
    'color_primary' => 'Primary',
    'color_success' => 'Success',
    'color_warning' => 'Warning',
    'color_destructive' => 'Danger',
    'color_info' => 'Info',
    'color_chart_1' => 'Palette 1',
    'color_chart_2' => 'Palette 2',
    'color_chart_3' => 'Palette 3',
    'color_chart_4' => 'Palette 4',
    'color_chart_5' => 'Palette 5',
    'color_chart_6' => 'Palette 6',
    'color_chart_7' => 'Palette 7',
    'color_chart_8' => 'Palette 8',

    // Importing an iCalendar file (`ICalReader`): the errors and warnings shown to whoever uploads
    // it. iCalendar names (VEVENT, DTSTART, RRULE, TZID…) are not translated: they are what the
    // user will look for in the file. `:line` is the line of the file.
    'ical_line_invalid' => 'Line :line: not a valid iCalendar property (expected :format): «:preview».',
    'ical_param_unclosed_quote' => 'Line :line: the parameter :param opens a quote and never closes it.',
    'ical_missing_colon' => 'Line :line: the property :name has no «:» before its value.',
    'ical_too_big' => 'The file is :size and the largest one read is :max (the maxBytes option of ICalReader, or `ical.max_bytes` in the config).',
    'ical_not_icalendar' => 'The content is not an iCalendar file: BEGIN:VCALENDAR was not found.',
    'ical_version' => 'Line :line: VERSION «:version» is not 2.0; it is read as iCalendar 2.0.',
    'ical_uid_repeated' => 'Line :line: the UID «:uid» is repeated; both events are kept.',
    'ical_end_without_begin' => 'Line :line: END::name without its BEGIN; ignored.',
    'ical_unclosed' => 'Line :line: the :name is never closed (END::name is missing).',
    'ical_unclosed_truncated' => 'Line :line: the :name is never closed (END::name is missing); the file looks truncated.',
    'ical_too_many_lines' => 'Line :line: the file has too many lines for its maximum size; reading stopped here.',
    'ical_too_many_events' => 'Line :line: the file has more than :max VEVENT (the maxEvents option); reading stopped here.',
    'ical_too_deep' => 'Line :line: components are nested more than :max levels deep; reading stopped here.',
    'ical_not_utf8' => 'The file is not UTF-8; it was read as Windows-1252.',
    'ical_no_dtstart' => 'Line :line: the VEVENT has no DTSTART.',
    'ical_many_rrule' => 'Line :line: the VEVENT has several RRULE; only the first one is used.',
    'ical_unrepresentable' => 'Line :line: :name cannot be represented and is ignored; the series may show extra or missing occurrences.',
    'ical_too_many_exdate' => 'Line :line: the VEVENT has more than :max EXDATE; the rest are ignored.',
    'ical_range_obsolete' => 'Line :line: RECURRENCE-ID;RANGE=:range is obsolete and not supported; it is treated as a single occurrence.',
    'ical_override_with_rrule' => 'Line :line: a modified occurrence cannot carry an RRULE; ignored.',
    'ical_exdate_without_rrule' => 'Line :line: EXDATE without RRULE excludes nothing; ignored.',
    'ical_no_uid_strict' => 'Line :line: the VEVENT has no UID (RFC 5545 requires one).',
    'ical_no_uid' => 'Line :line: the VEVENT has no UID; it was given the id «:uid».',
    'ical_url_discarded' => 'Line :line: the URL «:url» is neither relative nor http, https, mailto or tel; discarded.',
    'ical_event_error' => 'Line :line: event «:id»: :reason',
    'ical_too_many_attendees' => 'Line :line: the VEVENT has more than :max ATTENDEE; the rest are ignored.',
    'ical_date_invalid' => 'Line :line: :name «:value» is not a valid iCalendar date (expected 20261016, 20261016T090000 or 20261016T090000Z).',
    'ical_date_nonexistent' => 'Line :line: :name «:value» is not a date that exists.',
    'ical_duration_invalid' => 'Line :line: DURATION «:value» is not a valid duration (expected P1D, PT1H30M, P1W…).',
    'ical_rrule_invalid' => 'Line :line: invalid RRULE «:rule» (:reason).',
    'ical_tz_no_tzid' => 'Line :line: the VTIMEZONE has no TZID.',
    'ical_tz_incomplete' => 'Line :line: the :name of VTIMEZONE «:tzid» needs DTSTART, TZOFFSETFROM and TZOFFSETTO.',
    'ical_tz_rrule_not_yearly' => 'Line :line: the :name of VTIMEZONE «:tzid» has an RRULE that is not FREQ=YEARLY; a VTIMEZONE changes time once a year at most, so its definition is ignored.',
    'ical_tz_too_many_rdate' => 'Line :line: the VTIMEZONE «:tzid» has more than :max RDATE dates; its definition is ignored.',
    'ical_tz_empty' => 'Line :line: the VTIMEZONE «:tzid» defines no STANDARD or DAYLIGHT.',
    'ical_tz_offset_invalid' => 'Line :line: :name «:value» is not a valid offset (expected +0200 or -0500).',
    'ical_tz_datetime_invalid' => 'Line :line: :name «:value» is not a valid date-time (expected 20261025T030000).',
    'ical_tzid_unknown' => 'Line :line: the TZID «:tzid» is not a known time zone and the file does not include its VTIMEZONE, so the actual time cannot be determined.',
    'ical_tzid_fixed_offset' => 'Line :line: the TZID «:tzid» is not an IANA zone; its VTIMEZONE has no time changes and the fixed offset :offset was used.',
    'ical_tzid_inferred' => 'Line :line: the TZID «:tzid» is not an IANA zone; the definition in its VTIMEZONE was used, which matches «:zone».',
    'ical_tzid_unmatched' => 'Line :line: the TZID «:tzid» is not an IANA zone and none matches its VTIMEZONE; the offset :offset is used on each event\'s date (a series will not follow time changes).',
];
