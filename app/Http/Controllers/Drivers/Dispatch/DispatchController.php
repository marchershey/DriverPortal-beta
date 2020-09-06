<?php

namespace App\Http\Controllers\Drivers\Dispatch;

use App\Dispatch;
use App\DispatchStatus;
use App\DispatchStopType;
use App\Http\Controllers\Controller;
use App\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DispatchController extends Controller
{
    public function index()
    {
        //
    }

    public function start()
    {
        $statuses = DispatchStatus::where('driver_hidden', 0)->get();
        return view('sections.dispatch.start', compact('statuses'));
    }

    public function start_post(Request $request)
    {
        $data = $request->validate([
            'reference_number' => 'required|numeric|unique:App\Dispatch,reference_number',
            'stop_count' => 'required|numeric|min:1|max:10',
            'miles' => 'required|numeric|between:1,9999',
            'starting_date' => 'required|date|date_format:Y-m-d',
            'status' => 'required|numeric',
        ], [
            'reference_number.required' => '<span class="font-extrabold">Missing Reference Number</span><br>This can be found on your FirstFleet app or the paperwork.',
            'reference_number.numeric' => '<span class="font-extrabold">Invalid Reference Number</span><br>The Reference Number can only contain numbers.',
            'reference_number.unique' => '<span class="font-extrabold">Reference Number already assigned</span><br>That Reference Number has been assigned to a dispatch already.',
            'miles.between' => '<span class="font-extrabold">Missing or Invalid Miles</span><br>Ensure you have enter the correct Miles value.',
        ]);

        $dispatch = new Dispatch();
        $dispatch->reference_number = $data['reference_number'];
        $dispatch->stop_count = ($data['stop_count'] < 1) ? 1 : $data['stop_count'];
        $dispatch->pay = (($data['miles'] * Auth::user()->rates->mileage) + (($data['stop_count'] > 1) ? (($data['stop_count'] - 1) * Auth::user()->rates->stop_pay) : 0.00));
        $dispatch->miles = $data['miles'];
        $dispatch->starting_date = $data['starting_date'];
        $dispatch->status_id = $data['status'];
        $dispatch->user_id = Auth::user()->id;
        $dispatch->save();

        return redirect('./driver/dispatch/' . $dispatch->reference_number);
    }

    public function show($reference_number)
    {
        $dispatch = Dispatch::where('reference_number', $reference_number)->firstOrFail();
        $statuses = DispatchStatus::where('driver_hidden', 0)->get();
        $stopTypes = DispatchStopType::where('active', 1)->get();

        if ($dispatch->user_id === Auth::id()) {
            return view('sections.dispatch.show', compact('dispatch', 'statuses', 'stopTypes'));
        } else {
            abort(401);
        }

    }

    public function show_post(Request $request, $reference_number)
    {

        $dispatch = Dispatch::where('reference_number', $reference_number)->first();

        $data = $request->validate([
            'reference_number' => 'required|numeric',
            'miles' => 'required|numeric',
            'stop_count' => 'required|numeric|min:0|max:10',
            'starting_date' => 'required|date|date_format:Y-m-d',
            'status' => 'required|numeric',
            'position' => 'numeric',
            'stops' => '',
        ], [
            'reference_number.required' => '<span class="font-extrabold">Missing Reference Number</span>.<br>This can be found on your FirstFleet app or the paperwork.',
            'reference_number.numeric' => '<span class="font-extrabold">Invalid Reference Number</span>.<br>The Reference Number can only contain numbers.',
            'reference_number.exists' => '<span class="font-extrabold">Reference Number</span> doesn\'t exists.<br>That Reference Number does not exist.',
        ]);

        foreach ($data['stops'] as $key => $stop) {

            if ($stop['type_id'] == "2") {
                // roll off
                $data['stops'][$key]['roll_offs'] = $stop['tray_count'];
                $data['stops'][$key]['pack_outs'] = "0";
            } else if ($stop['type_id'] == "3") {
                // pack out
                if (isset($stop['tray_count'])) {
                    $data['stops'][$key]['roll_offs'] = $stop['tray_count'];
                    $data['stops'][$key]['pack_outs'] = $stop['tray_count'];
                }

                // $data['stops'][$key]['roll_offs'] = $stop['tray_count'];
                // $data['stops'][$key]['pack_outs'] = $stop['tray_count'];
            }

            // if pack out
            // if tray count

            // {
            //     if (isset($stop['tray_count'])) {
            //         $data['stops'][$key]['roll_offs'] = $stop['tray_count'];
            //         $data['stops'][$key]['pack_outs'] = $stop['tray_count'];
            //     }
            // }

            if (!isset($stop['different'])) {
                $data['stops'][$key]['different'] = 'off';
            }
        }

        $stops = collect($data['stops'])->whereNotNull('warehouse_id')->keyBy('warehouse_id')->transform(function ($item) {
            unset($item['warehouse_id']);
            foreach ($item as $key => $value) {
                if ($key != 'position' && $value == "0" || is_null($value)) {
                    unset($item[$key]);
                }
            }
            return $item;
        });

        // return $data;

        $pay = 0.00;
        $pay = $data['miles'] * Auth::user()->rates->mileage;
        foreach ($stops as $stop_item) {
            foreach ($stop_item as $item => $value) {
                if ($item == 'drop_hooks') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->drop_hook));
                } else if ($item == 'roll_off') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->roll_off));
                } else if ($item == 'tray_count') {
                    $pay = $pay + ((floatval($value) * floatval(Auth::user()->rates->pack_out)) + (floatval($value) * floatval(Auth::user()->rates->roll_off)));
                } else if ($item == 'pack_out') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->pack_out));
                } else if ($item == 'stop_pay') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->stop_pay));
                } else if ($item == 'pallets') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->pallet));
                } else if ($item == 'stale_count') {
                    $pay = $pay + (floatval($value) * floatval(Auth::user()->rates->stale));
                }
            }
        }

        $dispatch->update([
            'reference_number' => $data['reference_number'],
            'pay' => $pay,
            'miles' => $data['miles'],
            'stop_count' => $data['stop_count'],
            'starting_date' => $data['starting_date'],
            'status_id' => $data['status'],
            'stop_count' => $data['stop_count'],
        ]);

        $dispatch->stops()->detach();
        $dispatch->stops()->attach($stops);

        return redirect('./driver/dispatch/' . $data['reference_number'])->with('success', 'This dispatch has been updated.');
    }

    public function warehouse_search(Request $request)
    {
        $data = $request->validate([
            'string' => 'required',
        ]);

        $locations = Warehouse::where('name', 'like', '%' . $data['string'] . '%')->limit(3)->get();

        return $locations;
    }

    public function calc_rate(Request $request)
    {
        $rates = Auth::user()->rates()->first();

        $data = $request->validate([
            'value' => 'required|numeric',
            'stop_type' => 'required',
            'data_type' => 'required',
        ]);

        if ($data['data_type'] == 'miles') {
            $amount = $data['value'] * $rates->mileage;
        } else if ($data['data_type'] == 'drops') {
            $amount = $data['value'] * $rates->drop_hook;
        } else if ($data['data_type'] == 'stale') {
            $amount = $data['value'] * $rates->stale;
        } else if ($data['data_type'] == 'tray') {
            if ($data['stop_type'] == 'rolloff') {
                $amount = $data['value'] * $rates->roll_off;
            } else if ($data['stop_type'] == 'packout') {
                $amount = ($data['value'] * $rates->roll_off) + ($data['value'] * $rates->pack_out);
            }
        } else if ($data['data_type'] == 'rolloff') {
            $amount = $data['value'] * $rates->roll_off;
        } else if ($data['data_type'] == 'packout') {
            $amount = $data['value'] * $rates->pack_out;
        } else if ($data['data_type'] == 'stop') {
            $amount = ($data['value'] - 1) * $rates->stop_pay;
        } else if ($data['data_type'] == 'pallets') {
            $amount = ($data['value'] * $rates->pallet);
        }

        return number_format(round($amount, 2, PHP_ROUND_HALF_UP), 2);
    }

}
