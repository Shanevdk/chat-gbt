<?php

namespace App;

enum MessageChannel: string
{
    case Text = 'text';
    case Call = 'call';
}
