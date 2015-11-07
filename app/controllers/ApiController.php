<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of ApiController
 *
 * @author keitt
 */
class ApiController extends BaseController {

    public function __construct() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    public function getCreateAlbum() {
        $temp = new Gallery();
        $temp->name = NULL;
        $temp->detail = NULL;
        $temp->save();
        $last_id = $temp->id;

        $path = "gallery/{$last_id}";
        File::makeDirectory($path);

        $path = "gallery/{$last_id}/zip";
        File::makeDirectory($path);

        return Response::json(array(
                    'code' => 200,
                    'result' => $temp
        ));
    }

    public function postUpdateAlbum() {
        $gallery_id = Input::get('gallery_id', null);
        $name = Input::get('name', null);
        $detail = Input::get('detail', null);
        $status = Input::get('status', null);

        $pre_varify = array('gallery_id' => $gallery_id, 'name' => $name, 'detail' => $detail);
        $rules = array('gallery_id' => 'required|exists:gallery,id', 'name' => 'required', 'detail' => 'required');
        $valid = Validator::make($pre_varify, $rules);
        if ($valid->passes()) {
            $temp = Gallery::find($gallery_id);
            $temp->name = $name;
            $temp->detail = $detail;
            if (!is_null($status)) {
                $temp->status = $status;
            }
            $temp->save();
            $pre_varify['code'] = 200;
        } else {
            $pre_varify['code'] = 400;
        }
        $pre_varify['status'] = $status;
        return Response::json($pre_varify);
    }

    private function makeFile($each_file, $gallery_id) {
        /**
         * Upload
         */
        $new_name = round(microtime(true) * 1000);
        $file_name = "{$new_name}.{$each_file->getClientOriginalExtension()}";
        $save_path = "gallery/{$gallery_id}/zip";
        $each_file->move($save_path, $file_name);

        /**
         * Resize Main
         */
        $img = Image::make("{$save_path}/{$file_name}");
        $img->resize(2048, 2048, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $img->save();

        /**
         * Resize Thumb
         */
        $img->fit(350);
        $img->save("{$save_path}/../thumb_{$file_name}");

        /**
         * Save Change
         */
        $new_pic = new Pictures;
        $new_pic->gallery_id = $gallery_id;
        $new_pic->name = $file_name;
        $new_pic->save();
        
        return array(
            'id' => $new_pic->id,
            'name' => $new_pic->name,
            'thumb' => asset("gallery/{$gallery_id}/thumb_{$file_name}"),
            'url' => asset("gallery/{$gallery_id}/zip/{$file_name}"),
        );
    }

    public function postUploadImage() {
        set_time_limit(0);
        $resp = array();
        if (Input::hasFile('media')) {
            $gallery_id = Input::get('gallery_id');

            $file = Input::file('media');
            if (is_array($file)) {
                $resp['result'] = array();
                foreach ($file as $each_file) {
                    $resp['result'][] = $this->makeFile($each_file, $gallery_id);
                    $resp['multiple'] = true;
                }
            } else {
                $resp['result'] = $this->makeFile($file, $gallery_id);
                $resp['multiple'] = false;
            }

            $resp['code'] = 200;
        } else {
            $resp['code'] = 400;
        }
        return Response::json($resp);
    }

    public function getMakeZip() {
        set_time_limit(0);
        $all_gall = Gallery::select('id')->where('status', 'waiting')->limit(4)->get();
        foreach ($all_gall as $each_gallery) {
            $zip_path = public_path("gallery/{$each_gallery->id}/gallery_{$each_gallery->id}.zip");
            $read_path = public_path("gallery/{$each_gallery->id}/zip");
            Zipper::make($zip_path)->add($read_path);
            $gallery_id = Gallery::find($each_gallery->id);
            $gallery_id->status = "zip";
            $gallery_id->save();
        }

        return Response::json($all_gall->toArray());
    }

    public function getAllGallery() {
        $all = Input::get('all', 1);
        $all_active_gallery = Gallery::orderBy('id', 'desc');
        if ($all != 1) {
            $all_active_gallery->whereIn('status', array('waiting', 'zip'));
        }

        $all_gallery = $all_active_gallery->get();

        if (count($all_gallery)) {
            $mark_as_array = $all_gallery->toArray();
            foreach ($mark_as_array as &$each_rec) {
                $temp_pic = Pictures::select('id', 'created_at', 'name')
                        ->where('gallery_id', $each_rec['id'])
                        ->orderBy('id', 'desc')
                        ->first();

                if ($temp_pic) {
                    $temp_pic = $temp_pic->toArray();
                    $temp_pic['url'] = asset("gallery/{$each_rec['id']}/zip/{$temp_pic['name']}");
                    $temp_pic['thumb'] = asset("gallery/{$each_rec['id']}/thumb_{$temp_pic['name']}");
                }
                $each_rec['pictures'] = $temp_pic;
                $each_rec['zip_url'] = $each_rec['status'] == "zip" ? asset("gallery/{$each_rec['id']}/gallery_{$each_rec['id']}.zip") : NULL;
            }

            $resp = array(
                'code' => 200,
                'result' => $mark_as_array
            );
        } else {
            $resp = array(
                'code' => 400
            );
        }

        return Response::json($resp);
    }

    public function getPictureGallery() {
        /**
         * Get All Pic in Gallery
         */
        $resp = array();
        $gallery_id = Input::get('gallery_id', null);
        $valid = Validator::make(array('gallery_id' => $gallery_id), array('gallery_id' => 'required|numeric|exists:gallery,id'));
        if ($valid->passes()) {
            $all_active_gallery = Gallery::with('pictures')
                    ->orderBy('id', 'desc')
                    ->find($gallery_id);
            $all_active_gallery = $all_active_gallery->toArray();
            foreach ($all_active_gallery['pictures'] as &$each_picture) {
                unset($each_picture['gallery_id']);
                unset($each_picture['updated_at']);
                $each_picture['url'] = asset("gallery/{$all_active_gallery['id']}/zip/{$each_picture['name']}");
                $each_picture['thumb'] = asset("gallery/{$all_active_gallery['id']}/thumb_{$each_picture['name']}");
            }

            $all_active_gallery['zip_url'] = $all_active_gallery['status'] == "zip" ? asset("gallery/{$all_active_gallery['id']}/gallery_{$all_active_gallery['id']}.zip") : NULL;

            $resp = array(
                'code' => 200,
                'result' => $all_active_gallery
            );
        } else {
            $resp = array(
                'code' => 400
            );
        }

        return Response::json($resp);
    }

    public function getDelGallery($gallery_id = null) {
        $gallery_id = is_null($gallery_id) ? Input::get('gallery_id', null) : $gallery_id;
        $resp = array();
        $valid = Validator::make(array('gallery_id' => $gallery_id), array('gallery_id' => 'required|exists:gallery,id|numeric'));
        if ($valid->passes()) {
            $path = public_path("gallery/{$gallery_id}");
            $delete_result = File::deleteDirectory($path);
            if ($delete_result) {
                Pictures::where('gallery_id', $gallery_id)->delete();
                Gallery::where('id', $gallery_id)->delete();
                $resp = array('code' => 200);
            } else {
                $resp = array('code' => 500);
            }
        } else {
            $resp = array('code' => 400);
        }
        return Response::json($resp);
    }

    public function getDelPicture($picture_id = null) {
        $picture_id = is_null($picture_id) ? Input::get('picture_id', null) : $picture_id;
        $resp = array();
        $valid = Validator::make(array('picture_id' => $picture_id), array('picture_id' => 'required|exists:picture,id|numeric'));
        if ($valid->passes()) {
            $picture_info = Pictures::find($picture_id);
            $thumb_path = public_path("gallery/{$picture_info->gallery_id}/thumb_{$picture_info->name}");
            $full_path = public_path("gallery/{$picture_info->gallery_id}/zip/{$picture_info->name}");
            File::delete($thumb_path);
            $delete_result = File::delete($full_path);
            if ($delete_result) {
                Pictures::where('id', $picture_id)->delete();
                $resp = array('code' => 200);
            } else {
                $resp = array('code' => 500, 'result' => array($thumb_path, $full_path));
            }
        } else {
            $resp = array('code' => 400);
        }
        return Response::json($resp);
    }

}
