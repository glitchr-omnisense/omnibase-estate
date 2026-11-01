<?php

namespace Base\Estate\Enum;

/** Where a story's entry stands: works under way (the investments in progress) or done. */
enum StoryState: string
{
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
}
