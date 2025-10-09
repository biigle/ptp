<?php

namespace Biigle\Modules\Ptp\Traits;

use File;
use SplFileObject;
use Exception;
use Generator;

trait ParseConvertedAnnotations {

    /**
     * List of columns of the CSV file
     * @var array
     */
    protected $annotatedFileColumns = [
        'annotation_id',
        'points',
        'image_id',
        'label_id',
    ];

    /**
     * Number of lines to be processed when parsing the results of the PTP conversion
     * @var int
     */
    public static int $lineChunkSize = 10000;

    /**
     * Create a generator that iterates over $lineChunkSize lines of a CSV file containing annotation results from the PTP conversion.
     * @param $file CSV file to open
     * @return Generator
     */
    private function iterateOverCsvFile(
        string $file,
    ): Generator
    {
        if (File::missing($file)) {
            throw new Exception("Unable to find output file $file");
        }

        if (File::size($file) == 0) {
            throw new Exception('No annotations were converted!');
        }

        $iterator = $this->getCsvFile($file);

        $header = $iterator->fgetcsv();
        if ($header !== $this->annotatedFileColumns) {
            throw new Exception("Annotation file $file is malformed");
        }

        $chunk = [];
        $idx = 0;

        while (true) {
            $data = $iterator->fgetcsv();

            if (!$data) {
                break;
            }
            #Malformed row. Avoid handling it.
            if (count($data) != count($header)) {
                continue;
            }

            $tmpChunk = array_combine($header, $data);
            $tmpChunk['points'] = json_decode($tmpChunk['points']);
            $chunk[] = $tmpChunk;

            $idx += 1;
            if ($idx > 0 && ($idx % static::$lineChunkSize) === 0) {
                yield $chunk;
                $chunk = [];
            }
        }
        yield $chunk;
    }

    /**
     * Open A CSV file.
     * @param $file CSV file to open
     * @return SplFileObject
     */
    private static function getCsvFile(string $file): SplFileObject
    {
        $file = new SplFileObject($file);
        $file->setFlags(SplFileObject::READ_CSV);

        return $file;
    }
}
