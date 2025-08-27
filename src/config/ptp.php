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
    'ptp_script' => __DIR__.'/../resources/scripts/ptp.py',

    /*
    | Path for temporary files
    */
    'temp_dir' => sys_get_temp_dir(),

    /*
    | Path to store the model checkpoint to.
    */
    'model_path' => storage_path('ptp').'/sam_checkpoint.pth',

    /*
    | URL from which to download the model checkpoint.
    |
    | Important: The model checkpoint mst match with the ONNX file (see below)!
    |
    | See: https://github.com/facebookresearch/segment-anything#model-checkpoints
    */
    'model_url' => env('PTP_MODEL_URL', 'https://dl.fbaipublicfiles.com/segment_anything/sam_vit_h_4b8939.pth'),

    /*
    | The SAM model type.
    |
    | See: https://github.com/facebookresearch/segment-anything#model-checkpoints
    */
    'model_type' => env('PTP_MODEL_TYPE', 'vit_h'),

    'notifications' => [
        /*
        | Set the way notifications for PTP job state changes are sent by default.
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
