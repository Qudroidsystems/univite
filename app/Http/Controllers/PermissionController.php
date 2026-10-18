<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB as FacadesDB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    /**
     * create a new instance of the class
     *
     * @return void
     */
    function __construct()
    {
         $this->middleware('permission:View permissions|Create permissions|Edit permissions|Delete permissions', ['only' => ['index', 'show']]);
         $this->middleware('permission:Create permissions', ['only' => ['create', 'store']]);
         $this->middleware('permission:Edit permissions', ['only' => ['edit', 'update']]);
         $this->middleware('permission:Delete permissions', ['only' => ['destroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
         #page title
         $pagetitle = "Role Management";

        $data = Permission::get();
      
        return view('permissions.index', compact('data'))->with('pagetitle', $pagetitle);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Permissions are managed on the list page; there is no separate page for one.
        return redirect()->route('permissions.index');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
         #page title
         $pagetitle = "Role Management";
         
        $this->validate($request, [
            'name' => 'required|unique:permissions,name',
        ]);

        Permission::create(['name' => $request->input('name')]);

        return redirect()->route('permissions.index')
            ->with('success', 'Permission created successfully.')->with('pagetitle', $pagetitle);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // Permissions are managed on the list page; there is no separate page for one.
        return redirect()->route('permissions.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // Permissions are managed on the list page; there is no separate page for one.
        return redirect()->route('permissions.index');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
         #page title
         $pagetitle = "Role Management";

        $this->validate($request, [
            'name' => 'required'
        ]);

        $permission = Permission::find($id);
        $permission->name = $request->input('name');
        $permission->save();

        return redirect()->route('permissions.index')
            ->with('success', 'Permission updated successfully.')->with('pagetitle', $pagetitle);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        Permission::find($id)->delete();

        return redirect()->route('permissions.index')
            ->with('success', 'Permission deleted successfully');
    }


}
