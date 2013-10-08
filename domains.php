<?php

function is_legal_domain_part($part) {

        $legalcharacters = 'abcdefghijklmnopqrstuvwxyz0123456789-';
        $len = strlen($part);

        if ($len < 2 || $len > 64) { return FALSE; }

        $slash = true;
        $c = '';
        for ($i = 0; $i < $len; $i++) {
                $c = $part[ $i ];

                if (strripos($legalcharacters, $c) === false) {
                        return FALSE;
                }

                if ($c == '-') {
                        if ($slash) {
                                return FALSE;
                        } else {
                                $slash = true;
                        }
                } else {
                        $slash = false;
                }

        }

        if ($c == '-') {
                return FALSE;
        }

        return TRUE;

}


