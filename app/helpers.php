<?php

if(!function_exists('get_time_remaining')) {
    function get_time_remaining($time, $deadline = null) {
        $t = $deadline ? strtotime($time) - strtotime($deadlone) : $time;
        $l = 'seconds';
        $t > 60 && ($t /= 60) && $l = 'minutes';
        $t > 60 && ($t /= 60) && $l = 'hours';
        return number_format($t).' '.$l;
    }

    function json_to_array($json) {
        return (array) json_decode($json->content(), true);
    }
}