<?php

namespace App;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Event = 'event';
}
