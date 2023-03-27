<?php

namespace Visma;

interface VismaPayConnector
{
    public function request($url, $post_arr);
}
