<?php

return [
    /*
    | Queue to submit new PTP jobs to.
    */
    'job_queue' => env('PTP_JOB_QUEUE', 'default'),

    /*
    | Queue connection to submit new PTP jobs to.
    */
    'job_connection' => env('PTP_JOB_CONNECTION', 'gpu'),

    /*
    | Queue to submit PTP feature vector jobs to.
    */
    'feature_vector_queue' => env('PTP_FEATURE_VECTOR_QUEUE', env('PTP_JOB_QUEUE', 'default')),

    /*
    | Directory where the temporary files of PTP conversion should be stored.
    */
    'tmp_dir' => env('PTP_TMP_DIR', storage_path('ptp_jobs')),

    /*
    | Path to the Python executable.
    */
    'python' => env('PTP_PYTHON', '/usr/bin/python3'),

    /*
    | Path to the script that performs PTP conversion.
    */
    'ptp_script' => __DIR__.'/../resources/scripts/PTP.py',

    /*
    | URL from which to download the trained weights for the model.
    */
    'model_url' => env('PTP_MODEL_URL', 'https://download.openmmlab.com/mmdetection/v2.0/faster_rcnn/faster_rcnn_r50_fpn_1x_coco/faster_rcnn_r50_fpn_1x_coco_20200130-047c8118.pth'),

    /*
    | Path to the file to store the pretrained model weights to.
    */
    'model_path' => storage_path('ptp_jobs').'/faster_rcnn_r50_fpn_1x_coco_20200130-047c8118.pth',


    'notifications' => [
        /*
        | Set the way notifications for MAIA job state changes are sent by default.
        |
        | Available are: "email", "web"
        */
        'default_settings' => 'email',

        /*
        | Choose whether users are allowed to change their notification settings.
        | If set to false the default settings will be used for all users.
        */
        'allow_user_settings' => true,
    ],
];
