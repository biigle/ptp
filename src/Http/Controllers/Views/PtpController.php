<?php


namespace Biigle\Modules\Ptp\Http\Controllers\Views;

use Biigle\Http\Controllers\Views\Controller;
use Biigle\Role;
use Biigle\Volume;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Storage;


class PtpController extends Controller
{
    /**
     * Show the overview of MAIA jobs for a volume
     *
     * @param Request $request
     * @param int $id Volume ID
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, $id)
    {
        $volume = Volume::findOrFail($request->id);
        return view('ptp::index', compact(
            'volume',
                            ));

    }

}

