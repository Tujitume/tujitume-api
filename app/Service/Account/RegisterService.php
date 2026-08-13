<?php
namespace App\Service\Account;
use App\Models\Auth\User;
use App\Models\Capital\CapitalProfile;
use App\Models\Grants\GrantProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RegisterService
{
    public function investorRegister($data)
    {
        $investor = 1;
        $inv_range = $data['inv_range'];
        $interested_cats = $data['interested_cats'];
        $past_investment = $data['past_investment'];
        $website = $data['website'];
        $id_no = $data['id_no'];
        $tax_pin = $data['tax_pin'];

        $passport=$data['id_passport' ?? null];
        $pin=$data['pin'] ?? null;

        //File Type Check END!

        if(isset($request->switch) && $request->switch == 1)
        {
            $user = User::select('id')->where('email',$data['email'])->first();

            $update = User::where('email',$data['email'])
                ->update([
                    'user_type_id' => $investor,
                    'id_no' => $id_no,
                    'tax_pin' => $tax_pin,
                    'inv_range' =>  $inv_range,
                    'interested_cats' =>  $interested_cats,
                    'past_investment' => $past_investment,
                    'website' => $website
                ]);

        }
        else
        {
            $userCheck = User::where('email',$data['email'])->first();
            if($userCheck){
                return response()->json([ 'status' => 400, 'message' => 'Email already exists.'], 400);
            }
            //return $data['password'];
            $user = User::create([
                'fname' => $data['fname'],
                'mname' => $data['mname'],
                'lname' => $data['lname'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'user_type_id' => $investor,
                'id_no' => $id_no,
                'tax_pin' => $tax_pin,
                'inv_range' =>  $inv_range,
                'interested_cats' =>  $interested_cats,
                'turnover_range' =>  $data['turnover_range'],
                'stage' =>  $data['stage'],
                'regions_focus' =>  $data['regions_focus'],
                'social_impact_areas' =>  $data['social_impact_areas'],
                'past_investment' => $past_investment,
                'website' => $website
            ]);
        }

        //Upload
        $inv_id = $user->id;
        try {
            if (!file_exists('files/investor/'.$inv_id))
                mkdir('files/investor/'.$inv_id, 0777, true);
            $loc='files/investor/'.$inv_id.'/';
            if(isset($pin) && $pin !=null) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($pin->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                //Move uploaded file
                $pin->move($loc, $create_name);
                $final_pin=$loc.$create_name;
            } else $final_pin=null;

            if($passport) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($passport->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $passport->move($loc, $create_name);
                $final_passport=$loc.$create_name;
            }else $final_passport='';

            User::where('id',$inv_id)->update([
                'pin' => $final_pin,
                'id_passport' => $final_passport
            ]);
            $token = $user->createToken('main')->plainTextToken;
            return response()->json([
                'user' => $user,
                'token' => $token,
                'auth' => Auth::check()
            ]);

        } catch (\Exception $e) {
            return response()->json([ 'status' => 400, 'message' => $e->getMessage() ]);

        }
        //INVESTOR ACCOUNT ENDS
    }

    public function grantRegister($data)
    {
        $investor = 2;
        $interested_cats = $data['interested_cats'] ?? null;
        $website = $data['website'] ?? null;

        try {
            //File Type Check!
            $passport=$data['document'] ?? null;
            if($passport) {
                $ext=strtolower($passport->getClientOriginalExtension());

                $size=($passport->getSize())/1048576; // Get MB
                if($size == 2 || $size > 2)
                {
                    return response()->json([ 'status' => 400, 'message' => 'Document size must be less than 2MB!']);
                }

                if($ext!='pdf' && $ext!= 'docx')
                {
                    return response()->json([ 'status' => 400, 'message' => 'Only pdf & docx are allowed!']);
                }
            }

            //File Type Check END!

            $userCheck = User::where('email',$data['email'])->first();
            if($userCheck){
                return response()->json(['message' => 'Email already exists'], 400);
            }

            $password = bcrypt($data['password']) ?? null;
            $user = User::create([
                'fname' => $data['fname'],
                'email' => $data['email'],
                'password' => $password,
                'user_type_id' => $investor,
                'interested_cats' => $interested_cats ?? null,
                'phone' => $data['phone'] ?? null,
                'website' => $website ?? null,
                'social_impact_areas' =>  $data['social_impact_areas'] ?? null
            ]);

            //Upload
            $inv_id = $user->id;


            if (!file_exists('files/investor/'.$inv_id))
                mkdir('files/investor/'.$inv_id, 0777, true);
            $loc='files/investor/'.$inv_id.'/';

            if($passport) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($passport->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $passport->move($loc, $create_name);
                $final_passport=$loc.$create_name;
            }else $final_passport='';

            GrantProfile::create([
                'user_id'  => $user->id,
                'role_id'  => $data['role_id'] ?? null,
                'org_type' => $data['org_type'] ?? null,
                'mission'  => $data['mission'] ?? null,
                'regions'  => $data['regions'] ?? null,
                'document' => $final_passport,
            ]);


            //for special user
            $specialEmail = 'agrisokoo@gmail.com';

            if($data['email'] == $specialEmail){
                $expiresAt = now()->addMonths(6); // addMonths(6)
                $token = $user->createToken('main', ['*'], $expiresAt)->plainTextToken;
            }
            else
            {
                $token = $user->createToken('main')->plainTextToken;
            }

            return response()->json([
                'user' => $user,
                'token' => $token,
                'auth' => true
            ],200);

        } catch (\Exception $e) {
            return response()->json([ 'status' => 400, 'message' => $e->getMessage(),'line' => $e->getLine() ]);

        }
    }

    public function grantRoleUserRegister($data)
    {
        try {
            $userCheck = User::where('email',$data['email'])->first();
            if($userCheck)
            {
                return response()->json(['success' => false, 'message' => 'Email already exists'], 400);
            }

            $randomPassword = substr(bin2hex(random_bytes(5)), 0, rand(8, 10));
            $user = User::create([
                'fname' => $data['fname'],
                'email' => $data['email'],
                'password' => $randomPassword,
                'user_type_id' => 2,
            ]);

            $owner=User::select('id','email','fname','lname')->find($data['grant_owner_id']);
            GrantProfile::create([
                'user_id'  => $user->id,
                'role_id'  => $data['role_id'],
                'grant_owner_id'  => $data['grant_owner_id'] ?? null,
                'org_type' => $owner->grant_profile->org_type ?? null,
                'mission'  => $owner->grant_profile->mission ?? null,
                'regions'  => $owner->grant_profile->regions ?? null,
                'document' => $owner->grant_profile->document,
                'active' => 0,
            ]);

            // E M A I L
            $link = URL::signedRoute('reject.invitation', ['email' => $data['email']]);

            $roles = [
                10001 => 'admin',
                10002 => 'editor',
                10003 => 'viewer',
                10004 => 'internal_reviewer'
            ];

            $role_name = $roles[$data['role_id']] ?? 'unknown';

            $info=[
                'email'=>$data['email'], 'o_email' => $owner->email,
                'link' => $link, 'org' => 'Grant', 'role' => $role_name
            ];
            $user['to'] = $data['email']; $headers = "From: webmaster@Jitume.com";

            Mail::send('create_password', $info, function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject('Grant Manage Request');
            });
            // E M A I L
            return response()->json([
                'success' => true, 'user' => $user,
                'message' => 'User created successfully.'
            ], 200);

        }
        catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(),'line' => $e->getLine()], 400);

        }
    }


    public function invCapitalRegister($data)
    {
        $investor = 3;
        $interested_cats = $data['interested_cats'] ?? null;
        $website = $data['website'] ?? null;

        //File Type Check!
        try {
            $passport=$data['document'] ?? null;
            if($passport) {
                $ext=strtolower($passport->getClientOriginalExtension());

                $size=($passport->getSize())/1048576; // Get MB
                if($size == 3 || $size > 3)
                {
                    return response()->json([ 'status' => 422, 'message' => 'Document size must be less than 2MB!'], 422);
                }

                if($ext!='pdf' && $ext!= 'docx')
                {
                    return response()->json([ 'status' => 422, 'message' => 'Only pdf & docx are allowed!'], 422);
                }
            }

            //File Type Check END!

            $userCheck = User::where('email',$data['email'])->first();
            if($userCheck){
                return response()->json(['message' => 'Email already exists'], 400);
            }

            $randomPassword = substr(bin2hex(random_bytes(5)), 0, rand(8, 10));
            $password = bcrypt($data['password']);
            $user = User::create([
                'fname' => $data['fname'],
                'email' => $data['email'],
                'password' => $password ?? $randomPassword,
                'user_type_id' => $investor,
                'interested_cats' =>$interested_cats ?? [],
                'phone' => $data['phone'] ?? null,
                'inv_range' => $data['inv_range'] ?? null,
                'turnover_range' =>  $data['turnover_range'] ?? null,
                'website' => $website ?? null,
                'social_impact_areas' =>  $data['social_impact_areas'] ?? null,
            ]);
            //Upload
            $inv_id = $user->id;

            if (!file_exists('files/investor/'.$inv_id))
                mkdir('files/investor/'.$inv_id, 0777, true);
            $loc='files/investor/'.$inv_id.'/';

            if($passport) {
                $uniqid=hexdec(uniqid());
                $ext=strtolower($passport->getClientOriginalExtension());
                $create_name=$uniqid.'.'.$ext;
                $passport->move($loc, $create_name);
                $final_passport=$loc.$create_name;
            }else $final_passport='';

            CapitalProfile::create([
                'user_id'  => $user->id,
                'role_id'  => $data['role_id'] ?? null,
                'org_type' => $data['org_type'] ?? null,
                'startup_stage' => $data['stage'] ?? null,
                'eng_prefer' => $data['eng_prefer'] ?? null,
                'regions'  => $data['regions_focus'] ?? null,
                'document' => $final_passport,
            ]);

            $token = $user->createToken('main')->plainTextToken;
            return response()->json([
                'user' => $user,
                'token' => $token,
                'auth' => true
            ]);

        } catch (\Exception $e) {
            return response()->json([ 'status' => 400, 'message' => $e->getMessage() ]);

        }
    }

    public function capitalRoleUserRegister($data)
    {
        try {
            $userCheck = User::where('email',$data['email'])->first();
            if($userCheck)
            {
                return response()->json([ 'message' => 'Email already exists'], 400);
            }

            $randomPassword = substr(bin2hex(random_bytes(5)), 0, rand(8, 10));
            $user = User::create([
                'fname' => $data['fname'],
                'email' => $data['email'],
                'password' => $randomPassword,
                'user_type_id' => 3,
            ]);

            $owner=User::select('id','email','fname','lname')->find($data['capital_owner_id']);
            CapitalProfile::create([
                'user_id'  => $user->id,
                'role_id'  => $data['role_id'],
                'capital_owner_id'  => $data['capital_owner_id'],
                'startup_stage' => $owner->capital_profile->startup_stage ?? null,
                'eng_prefer' => $owner->capital_profile->eng_prefer ?? null,
                'regions'  => $owner->capital_profile->regions ?? null,
                'active' => 0,
            ]);

            // E M A I L
            $role_name = $data['role_id'] == 10001
                ? 'admin'
                : ($data['role_id'] == 10002 ? 'editor' : 'viewer');

            $info=[
                'email'=>$data['email'], 'o_email'=>$owner->email,
                'org' => 'Capital', 'role' => $role_name
            ];
            $user['to'] = $data['email']; $headers = "From: webmaster@Jitume.com";

            Mail::send('create_password', $info,  function($msg) use ($user){
                $msg->to($user['to']);
                $msg->subject('Capital Manage Request');
            });
            // E M A I L
            return response()->json(['success' => true, 'message' => 'User created successfully.'], 200);

        }
        catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(),'line' => $e->getLine()], 400);

        }
    }


}
