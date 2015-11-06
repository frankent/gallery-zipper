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
        
    }

    public function getCreateAlbum() {
        $temp = new Gallery();
        $temp->save();
        $last_id = $temp->id;

        $path = "gallery/{$last_id}";
        File::makeDirectory($path);

        $path = "gallery/{$last_id}/zip";
        File::makeDirectory($path);

        return Response::json(array(
                    'code' => 200,
                    'current_id' => $last_id
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
        $img->resize(350, 350, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $img->save("{$save_path}/../thumb_{$file_name}");

        /**
         * Save Change
         */
        $new_pic = new Picture;
        $new_pic->gallery_id = $gallery_id;
        $new_pic->name = $file_name;
        $new_pic->save();
    }

    public function postUploadImage() {
        $resp = array();
        if (Input::hasFile('media')) {
            $gallery_id = Input::get('gallery_id');

            $file = Input::file('media');
            if (is_array($file)) {
                foreach ($file as $each_file) {
                    $this->makeFile($each_file, $gallery_id);
                }
            } else {
                $this->makeFile($file, $gallery_id);
            }

            $resp['code'] = 200;
        } else {
            $resp['code'] = 400;
        }
        return Response::json($resp);
    }

    public function getMakeZip() {
        set_time_limit(0);
        $all_gall = Gallery::select('id')->where('status', 'waiting')->get();
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

    public function getPictureGallery() {
        /**
         * Get All Pic in Gallery
         */
        $resp = array();
        $gallery_id = Input::get('gallery_id', null);
        $valid = Validator::make(array('gallery_id' => $gallery_id), array('gallery_id' => 'required|numeric|exists:gallery,id'));
        if ($valid->passes()) {
            $all_active_gallery = Gallery::with('picture')
                    ->orderBy('id', 'desc')
                    ->find($gallery_id);
            $all_active_gallery = $all_active_gallery->toArray();
            foreach ($all_active_gallery['picture'] as &$each_picture) {
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

}
