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
    'persist_failed' => 'The change could not be saved',
    'event_moved' => '“:title” moved to :date',
    'event_resized' => '“:title” now ends on :date',
    'event_resized_start' => '“:title” now starts on :date',

    // What anyone sees who has not implemented a `KoreCalendar` hook yet (persistMove,
    // persistResize, createEvent, updateEvent, deleteEvent): it is the rejection reason.
    'hook_missing' => 'This calendar does not save that change yet: implement :hook() in :class.',

    // `->highlightConflicts()`: the screen reader text of an event that overlaps others.
    'conflict' => 'Conflicts with :with',

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
];
