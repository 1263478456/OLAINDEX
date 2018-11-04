<?php

namespace App\Http\Controllers;

use App\Helpers\Tool;
use Illuminate\Http\Request;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Artisan;

/**
 * 管理员 OneDrive 操作
 * Class ManageController
 * @package App\Http\Controllers
 */
class ManageController extends Controller
{
    /**
     * @var OneDriveController
     */
    public $od;

    /**
     * GraphController constructor.
     */
    public function __construct()
    {
        $this->middleware('checkAuth')->except(['uploadImage', 'deleteItem']);
        $this->middleware('checkToken');
        $od = new OneDriveController();
        $this->od = $od;

    }

    /**
     * 图片上传
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\JsonResponse|\Illuminate\View\View
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadImage(Request $request)
    {
        if (!$request->isMethod('post'))
            return view('image');
        $field = 'olaindex_img';
        if (!$request->hasFile($field)) {
            $data = ['code' => 500, 'message' => '上传文件为空'];
            return response()->json($data);
        }
        $file = $request->file($field);
        $rule = [$field => 'required|max:4096|image'];
        $validator = \Illuminate\Support\Facades\Validator::make(request()->all(), $rule);
        if ($validator->fails()) {
            $data = ['code' => 500, 'message' => $validator->errors()->first()];
            return response()->json($data);
        }
        if (!$file->isValid()) {
            $data = ['code' => 500, 'message' => '文件上传出错'];
            return response()->json($data);
        }
        $path = $file->getRealPath();
        if (file_exists($path) && is_readable($path)) {
            $content = fopen($path, 'r');
            $image_hosting_path = trim(Tool::handleUrl(Tool::config('image_hosting_path')), '/');
            $filePath = trim($image_hosting_path . '/' . date('Y') . '/' . date('m') . '/' . date('d') . '/' . str_random(8) . '/' . $file->getClientOriginalName(), '/');
            $remoteFilePath = Tool::convertPath($filePath); // 远程图片保存地址
            $response = $this->od->uploadByPath($remoteFilePath, $content);
            $sign = $response['id'] . '.' . encrypt($response['eTag']);
            $fileIdentifier = encrypt($sign);
            $data = [
                'code' => 200,
                'data' => [
                    'id' => $response['id'],
                    'filename' => $response['name'],
                    'size' => $response['size'],
                    'time' => $response['lastModifiedDateTime'],
                    'url' => route('view', $filePath),
                    'delete' => route('delete', $fileIdentifier)
                ]
            ];
            @unlink($path);
            return response()->json($data);
        } else {
            $data = ['code' => 500, 'message' => '无法获取文件内容'];
            return response()->json($data);
        }
    }


    /**
     * 文件上传
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\JsonResponse|\Illuminate\View\View
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadFile(Request $request)
    {
        if (!$request->isMethod('post')) return view('admin.file');
        $field = 'olaindex_file';
        $target_directory = $request->get('root', '/');
        if (!$request->hasFile($field)) {
            $data = ['code' => 500, 'message' => '上传文件或目录为空'];
            return response()->json($data);
        }
        $file = $request->file($field);
        $rule = [$field => 'required|max:4096']; // 上传文件规则，单文件指定大小4M
        $validator = \Illuminate\Support\Facades\Validator::make(request()->all(), $rule);
        if ($validator->fails()) {
            $data = ['code' => 500, 'message' => $validator->errors()->first()];
            return response()->json($data);
        }
        if (!$file->isValid()) {
            $data = ['code' => 500, 'message' => '文件上传出错'];
            return response()->json($data);
        }
        $path = $file->getRealPath();
        if (file_exists($path) && is_readable($path)) {
            $content = fopen($path, 'r');
            $storeFilePath = trim(Tool::handleUrl($target_directory), '/') . '/' . $file->getClientOriginalName(); // 远程保存地址
            $remoteFilePath = Tool::convertPath($storeFilePath); // 远程文件保存地址
            $response = $this->od->uploadByPath($remoteFilePath, $content);
            $data = [
                'code' => 200,
                'data' => [
                    'id' => $response['id'],
                    'filename' => $response['name'],
                    'size' => $response['size'],
                    'time' => $response['lastModifiedDateTime'],
                ]
            ];
            @unlink($path);
            return response()->json($data);
        } else {
            $data = ['code' => 500, 'message' => '无法获取文件内容'];
            return response()->json($data);
        }
    }

    /**
     * 加密目录
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function lockFolder(Request $request)
    {
        try {
            $path = decrypt($request->get('path'));
        } catch (DecryptException $e) {
            Tool::showMessage($e->getMessage(), false);
            return view('message');
        }
        $password = $request->get('password', '12345678');
        $storeFilePath = trim($path, '/') . '/.password';
        $remoteFilePath = Tool::convertPath($storeFilePath); // 远程password保存地址
        $response = $this->od->uploadByPath($remoteFilePath, $password);
        $response ? Tool::showMessage('操作成功，请牢记密码！') : Tool::showMessage('加密失败！', false);
        Artisan::call('cache:clear');
        return redirect()->back();
    }

    /**
     * 新建 head/readme.md 文件
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createFile(Request $request)
    {
        if (!$request->isMethod('post')) return view('admin.add');
        $name = $request->get('name');
        try {
            $path = decrypt($request->get('path'));
        } catch (DecryptException $e) {
            Tool::showMessage($e->getMessage(), false);
            return view('message');
        }
        $content = $request->get('content');
        $storeFilePath = trim($path, '/') . '/' . $name . '.md';
        $remoteFilePath = Tool::convertPath($storeFilePath); // 远程md保存地址
        $response = $this->od->uploadByPath($remoteFilePath, $content);
        $response ? Tool::showMessage('添加成功！') : Tool::showMessage('添加失败！', false);
        Artisan::call('cache:clear');
        return redirect()->route('home', Tool::handleUrl($path));

    }

    /**
     * 编辑文本文件
     * @param Request $request
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateFile(Request $request, $id)
    {
        if (!$request->isMethod('post')) {
            $file = $this->od->getItem($id);
            $file['content'] = Tool::getFileContent($file['@microsoft.graph.downloadUrl']);
            return view('admin.edit', compact('file'));
        }
        $content = $request->get('content');
        $response = $this->od->upload($id, $content);
        $response ? Tool::showMessage('修改成功！') : Tool::showMessage('修改失败！', false);
        Artisan::call('cache:clear');
        return redirect()->back();
    }

    /**
     * 创建目录
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createFolder(Request $request)
    {
        try {
            $path = decrypt($request->get('path'));
        } catch (DecryptException $e) {
            Tool::showMessage($e->getMessage(), false);
            return view('message');
        }
        $name = $request->get('name');
        $graphPath = Tool::convertPath($path);
        $response = $this->od->mkdirByPath($name, $graphPath);
        $response ? Tool::showMessage('新建目录成功！') : Tool::showMessage('新建目录失败！', false);
        Artisan::call('cache:clear');
        return redirect()->back();
    }

    /**
     * 删除文件
     * @param $sign
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteItem($sign)
    {
        try {
            $deCode = decrypt($sign);
        } catch (DecryptException $e) {
            Tool::showMessage($e->getMessage(), false);
            return view('message');
        }
        $reCode = explode('.', $deCode);
        $id = $reCode[0];
        try {
            $eTag = decrypt($reCode[1]);
        } catch (DecryptException $e) {
            Tool::showMessage($e->getMessage(), false);
            return view('message');
        }
        $this->od->deleteItem($id, $eTag);
        Tool::showMessage('文件已删除');
        Artisan::call('cache:clear');
        return view('message');
    }

    /**
     * 复制
     * @param $sourcePath
     * @param $targetPath
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function copyItem($sourcePath, $targetPath)
    {
        $itemId = $this->od->pathToItemId($sourcePath);
        $parentItemId = $this->od->pathToItemId($targetPath);
        $response = $this->od->copy($itemId, $parentItemId);
        Artisan::call('cache:clear');
        return $response; // 返回复制进度
    }

    /**
     * 移动
     * @param $sourcePath
     * @param $targetPath
     * @param string $itemName
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function moveItem($sourcePath, $targetPath, $itemName = '')
    {
        $itemId = $this->od->pathToItemId($sourcePath);
        $parentItemId = $this->od->pathToItemId($targetPath);
        $response = $this->od->move($itemId, $parentItemId, $itemName);
        Artisan::call('cache:clear');
        return $response;
    }

    /**
     * 创建分享链接
     * @param $itemPath
     * @return false|mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createShareLink($itemPath)
    {
        $itemId = $this->od->pathToItemId($itemPath);
        $response = $this->od->createShareLink($itemId);
        return $response; // 返回链接
    }
    /**
     * 创建分享链接
     * @param $itemPath
     * @return false|mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteShareLink($itemPath)
    {
        $itemId = $this->od->pathToItemId($itemPath);
        $this->od->deleteShareLink($itemId);
        return [];
    }
}
