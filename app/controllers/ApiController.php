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
        $site_id = Input::get("site_id", NULL);
        if($site_id != NULL){
            $temp = new Gallery();
            $temp->name = NULL;
            $temp->site_id = $site_id;
            $temp->detail = NULL;
            $temp->save();
            $last_id = $temp->id;

            $path = "gallery/{$last_id}";
            File::makeDirectory($path);

            $path = "gallery/{$last_id}/zip";
            File::makeDirectory($path);   
            
            $resp = array(
                'code' => 200,
                'result' => $temp
            );
        }else{
            $resp = array(
                'code' => 400
            );
        }
        
        return Response::json($resp);
    }

    public function postUpdateAlbum() {
        $gallery_id = Input::get('gallery_id', null);
        $name = Input::get('name', null);
        $detail = Input::get('detail', null);
        $status = Input::get('status', null);
        $site_id = Input::get("site_id", NULL);

        $pre_varify = array('gallery_id' => $gallery_id, 'name' => $name, 'detail' => $detail, 'site_id' => $site_id);
        $rules = array('gallery_id' => 'required|exists:gallery,id', 'name' => 'required', 'detail' => 'required', 'site_id' => 'required');
        $valid = Validator::make($pre_varify, $rules);
        if ($valid->passes()) {
            $temp = Gallery::where('id', $gallery_id)
                    ->where('site_id', $site_id)
                    ->first();
            
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
        $new_pic = new GalleryPicture;
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
    
    private function recheckZip(){
        $all_zip = Gallery::select('id', 'created_at')->where('status', 'zip')->get();
        foreach($all_zip as $each_gallery){
            
            $current = time();
            $album_time = strtotime($each_gallery->created_at) + 2592000;
            
            if(($album_time - $current) > 0){
                $zip_path = public_path("gallery/{$each_gallery->id}/gallery_{$each_gallery->id}.zip");
                if(!File::exists($zip_path)){
                    $temp = Gallery::find($each_gallery->id);
                    $temp->status = 'waiting';
                    $temp->save();
                }
            }else{
                $this->getDelGallery($each_gallery->id);
            }
        }
    }

    public function getMakeZip() {
        set_time_limit(0);
        $this->recheckZip();
        $limit = Input::get('limit', 2);
        $all_gall = Gallery::select('id')->where('status', 'waiting')->limit($limit)->get();
        foreach ($all_gall as $each_gallery) {
            $del_path = public_path("gallery/{$each_gallery->id}/gallery_{$each_gallery->id}.*");
            $zip_path = public_path("gallery/{$each_gallery->id}/gallery_{$each_gallery->id}.zip");
            $read_path = public_path("gallery/{$each_gallery->id}/zip");
            
            foreach(glob($del_path) as $del_file){
                @File::delete($del_file);
            }

            
            $x = Zipper::make($zip_path)->add($read_path);
            if($x){
                $gallery_id = Gallery::find($each_gallery->id);
                $gallery_id->status = "zip";
                $gallery_id->save();
            }
        }

        return Response::json($all_gall->toArray());
    }

    public function getAllGallery() {
        $all = Input::get('all', 1);
        $site_id = Input::get('site_id', NULL);
        if($site_id != NULL){
            $all_active_gallery = Gallery::orderBy('id', 'desc');
            if ($all != 1) {
                $all_active_gallery->whereIn('status', array('waiting', 'zip'));
            }
            $all_active_gallery->where('site_id', $site_id);
            $all_gallery = $all_active_gallery->get();

            if (count($all_gallery)) {
                $mark_as_array = $all_gallery->toArray();
                foreach ($mark_as_array as &$each_rec) {
                    $temp_pic = GalleryPicture::select('id', 'created_at', 'name')
                            ->where('gallery_id', $each_rec['id'])
                            ->orderBy('id', 'asc')
                            ->first();

                    if ($temp_pic) {
                        $temp_pic = $temp_pic->toArray();
                        $temp_pic['url'] = asset("gallery/{$each_rec['id']}/zip/{$temp_pic['name']}");
                        $temp_pic['thumb'] = asset("gallery/{$each_rec['id']}/thumb_{$temp_pic['name']}");
                    }
                    $each_rec['gallery_picture'] = $temp_pic;
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
        }else{
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
        $site_id = Input::get('site_id', NULL);
        $valid = Validator::make(array('gallery_id' => $gallery_id, 'site_id' => $site_id), array('gallery_id' => 'required|numeric|exists:gallery,id', 'site_id' => 'required'));
        if ($valid->passes()) {
            $all_active_gallery = Gallery::with('gallery_picture')
                    ->orderBy('id', 'desc')
                    ->where('id', $gallery_id)
                    ->where('site_id', $site_id)
                    ->first();
            
            $all_active_gallery = $all_active_gallery->toArray();
            foreach ($all_active_gallery['gallery_picture'] as &$each_picture) {
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
    
    /**
     * 
     * @param type $path
     * @param type $gallery_id
     * @return int
     */
    private function deleteAlbum($path, $gallery_id){
        $delete_result = File::deleteDirectory($path);
        if ($delete_result) {
            GalleryPicture::where('gallery_id', $gallery_id)->delete();
            Gallery::where('id', $gallery_id)->delete();
            $resp = array('code' => 200);
        } else {
            $resp = array('code' => 500);
        }
        return $resp;
    }

    /**
     * 
     * @param type $gallery_id
     * @return type
     */
    public function getDelGallery($gallery_id = null) {
        $is_cli = false;
     
        if($gallery_id == null){
            $gallery_id = Input::get('gallery_id', null);
            $is_cli = true;
            $site_id = Input::get('site_id', null);
                        
            $valid_sample = array(
                'gallery_id' => $gallery_id,
                'site_id' => $site_id
            );
            
            $valid_rule = array(
                'gallery_id' => 'required|exists:gallery,id|numeric',
                'site_id' => 'required'
            );
        }else{
            $valid_sample = array('gallery_id' => $gallery_id);
            $valid_rule = array('gallery_id' => 'required|exists:gallery,id|numeric');
            $is_cli = false;
        }
        
        $resp = array();
        $valid = Validator::make($valid_sample, $valid_rule);
        if ($valid->passes()) {            
            
            $path = public_path("gallery/{$gallery_id}");            
            if($is_cli){     
                /**
                 * request from Cli
                 */
                $resp = $this->deleteAlbum($path, $gallery_id);
            }else{
                /**
                 * request from User
                 */
                $total = Gallery::where('id', $gallery_id)->where('site_id', $site_id)->count();
                if($total > 0){                    
                    $resp = $this->deleteAlbum($path, $gallery_id);
                }else{
                    $resp = array('code' => 503);
                }
            }
        } else {
            $resp = array('code' => 400);
        }
        return Response::json($resp);
    }

    /**
     * 
     * @param type $picture_id
     * @return type
     */
    public function getDelPicture($picture_id = null) {
        $picture_id = is_null($picture_id) ? Input::get('picture_id', null) : $picture_id;
        $resp = array();
        $valid = Validator::make(array('picture_id' => $picture_id), array('picture_id' => 'required|exists:picture,id|numeric'));
        if ($valid->passes()) {
            $picture_info = GalleryPicture::find($picture_id);
            $thumb_path = public_path("gallery/{$picture_info->gallery_id}/thumb_{$picture_info->name}");
            $full_path = public_path("gallery/{$picture_info->gallery_id}/zip/{$picture_info->name}");
            File::delete($thumb_path);
            $delete_result = File::delete($full_path);
            if ($delete_result) {
                GalleryPicture::where('id', $picture_id)->delete();
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
