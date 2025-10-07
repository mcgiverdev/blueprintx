<?php

namespace BlueprintX\Docs;

use cebe\openapi\spec\OpenApi;

class OpenApi31 extends OpenApi
{
    public function performValidation()
    {
        $this->requireProperties(['openapi', 'info', 'paths']);

        if (!empty($this->openapi) && !preg_match('/^3\.(0|1)\.[0-9]+(?:[-+].*)?$/i', $this->openapi)) {
            $this->addError('Unsupported openapi version: ' . $this->openapi);
        }
    }
}
