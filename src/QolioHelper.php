<?php

namespace Qolio\Helper;

class QolioHelper {
    static public function getRequestData(?string $fieldName = null): mixed
    {
        $getData = $_GET;

        $postData = $_POST;

        $inputData = file_get_contents("php://input");
        if (!empty($inputData)) {
            $jsonData = json_decode($inputData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $postData = array_merge($postData, $jsonData);
            }
        }

        $allData = array_merge($getData, $postData);

        if ($fieldName !== null) {
            return $allData[$fieldName] ?? null;
        }

        return $allData;
    }
}