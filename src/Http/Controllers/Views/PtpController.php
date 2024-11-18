<?php

namespace Biigle\Modules\Ptp\Http\Controllers\Views;

use Biigle\Http\Controllers\Views\Controller;
use Biigle\Role;
use Biigle\Volume;
use Biigle\ImageAnnotation;
use Biigle\Label;
use Biigle\LabelTree;
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

        if (!$request->user()->can('sudo')) {
            $this->authorize('edit-in', $volume);
        }

        if ($request->user()->can('sudo')) {
            // Global admins have no restrictions.
            $projects = $volume->projects;
        } else {
            // All projects that the user and the volume have in common
            // and where the user is editor, expert or admin.
            $projects = Project::inCommon($request->user(), $volume->id, [
                Role::editorId(),
                Role::expertId(),
                Role::adminId(),
            ])->get();
        }

        // All label trees that are used by all projects which are visible to the user.
        $labelTrees = LabelTree::select('id', 'name', 'version_id')
            ->with('labels', 'version')
            ->whereIn('id', function ($query) use ($projects) {
                $query->select('label_tree_id')
                    ->from('label_tree_project')
                    ->whereIn('project_id', $projects->pluck('id'));
            })
            ->get('id');

        //labels the user has access to
        $labels = Label::whereIn('label_tree_id', $labelTrees->pluck('id'))->get()->pluck('id');

        // ID of point shape
        $pointAnnotationId = 1;

        //annotation with labels and that are points
        $annotations = ImageAnnotation::join('image_annotation_labels','image_annotations.id', '=', 'image_annotation_labels.annotation_id')
            ->join('images','image_annotations.image_id','=','images.id')
            ->join('labels', 'image_annotation_labels.label_id','=','labels.id')
            ->where('images.volume_id', $volume->id)
            ->whereIn('image_annotation_labels.label_id', $labels)
            ->where('image_annotations.shape_id', $pointAnnotationId)
            ->select('images.uuid', 'image_annotations.id', 'image_annotation_labels.label_id', 'labels.name AS label_name')->get();

        return view('ptp::index', compact('volume'), ['annotations' => $annotations, 'labels'=>$labels]);

    }

}
