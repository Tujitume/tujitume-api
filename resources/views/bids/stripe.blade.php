<!DOCTYPE html>
<html lang="en">

<head>
   <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
     <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css"/>
   <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta name="description" content="">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge"/>
    <meta name="keywords" content="bibimcart, ">
    <meta name="author" content="">



   </head>



<body>
<div class="container">

@if(Session::has('Stripe_failed'))
        <!-- Pop up Modal -->
            <div class="success_message modal" style="display:block;" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content popup_success">

                        <div class="modal-body">
                            <h2 class="my-4 modal-title text-center w-100" id="exampleModalLabel">Failed</h2>

                            <p class="text-center text-danger">{{Session::get('Stripe_failed')}} @php Session::forget('Stripe_failed'); @endphp</p>
                        </div>
                        <div class="modal-footer">
                            <button onclick="popupClose();" type="button" class="w-50 py-2 my-3 h5 m-auto btn text-white" style="background:red;font-size: 18px;" data-dismiss="modal">Ok</button>

                        </div>
                    </div>
                </div>
            </div>
            <!-- Pop up Modal -->
        @endif
	
  <div class="col-sm-12">

         </div>
         <div class=" bg-black mx-auto ">

<!-- main form wrapper -->

            <!-- Form Starts Here -->
                     <form     
                        role="form"
                        action="{{ route('bidCommits') }}"
                        method="post"
                        class="require-validation m-auto"
                        data-cc-on-file="false"
                        data-stripe-publishable-key="pk_test_51JFWrpJkjwNxIm6zf1BN9frgMmLdlGWlSjkcdVpgVueYK5fosCf1fAKlMpGrkfGoiXGMb0PpcMEOdINTEVcJoCNa00tJop21w6"
                        id="payment-form">
                        @csrf


           <div  class="col-md-12 d-flex m-auto col-md-offset-3 py-2"> <!-- main form sizing -->
                                       
               <div class="flex flex-col-reverse pt-5" style="margin-top: 40px !important; width: 45% !important; padding: 10px 25px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1) !important;">

                        <div class=" "> <a class="btn  text-dark font-weight-bold " href="{{route('/')}}">Back to home</a></div>


                <h5 class="text-center py-3" style="color:rgb(22 101 52) !important;">Pay with your Credit/Debit Card via Stripe    <i  class="fab fa-cc-mastercard fa-1x"></i> <i style="color:red" class="fab fa-cc-visa fa-1x"></i> </h5>


 <div class="leftside flex">
                           <!-- email -->


                            <div class='form-row row my-2'>
                           <div class='col-sm-12  form-group required'>
                              <label class='control-label  ' style="color: #666666; font-size: 12px;"><b>  Email </b></label> 
                              <input class='form-control' size='4' name="email" id="" type='email'  >

                           </div> 
 
                        </div> 

                        <!-- name on card -->

                        <div class='form-row row my-2'>
                           <div class='col-sm-12  form-group required'>
                              <label class='control-label' style="color: #666666; font-size: 12px;"><b> Name on Card </b></label> <input name="name" 
                                 class='form-control' size='4' type='text'>
                           </div>


                        </div>

                        <!-- card number -->

                        <div class='form-row row my-2'>
                           <div class='col-sm-12 form-group  required'>
                              <label class='control-label' style="color: #666666; font-size: 12px;"><b> Card Number </b></label> <input autocomplete='on'
                                 autocomplete='off' class='form-control card-number' size='20'
                                 type='text'>

                                          

                           </div>

                         
                        </div>

          <div class='form-row row my-2'>
                           <div class='col-xs-12 col-md-4 form-group cvc required'>
                              <label class='control-label' style="color: #666666; font-size: 12px;"><b> CVC </b></label> <input autocomplete='off'
                                 class='form-control card-cvc' placeholder='ex. 311' size='4'
                                 type='text'>
                           </div>
                           <div class='col-xs-12 col-md-4 form-group expiration required'>
                              <label class='control-label ' style="color: #666666; font-size: 12px;"><b> Exp. Month </b></label> <input autocomplete='on'
                                 class='form-control card-expiry-month' placeholder='MM/Ex.  07' size='2'
                                 type='text'>
                           </div>
                           <div class='col-xs-12 col-md-4 form-group expiration required'>
                              <label class='control-label' style="color: #666666; font-size: 12px;"><b> Exp. Year </b></label> <input autocomplete='on'
                                 class='form-control card-expiry-year' placeholder='YYYY/Ex. 2022' size='4'
                                 type='text'>
                           </div>
                        </div>



                       
 



                       </div>


                </div>

               <div class="panel m-auto panel-default credit-card-box">
                  <div class="panel-heading display-table" >
                     <div class="row display-tr" >
                        <div class="display-td" >                            
                           
                        </div>
                     </div>
                  </div>
                  <div class="panel-body flex">
                     @if (Session::has('success'))
                     <div class="alert alert-success text-center">
                        <a href="#" class="close" data-dismiss="alert" aria-label="close">×</a>
                        <p>{{ Session::get('success') }}</p>
                     </div>
                     @endif



                        

                        <!-- Shipping address  starts -->

                         
                     <div class="row error mx-1 text-center collapse"><p style="color:#e31313; background: #cfcfcf82;font-weight: 600;" class="alert my-2 py-1 w-100"></p></div> 

                         
                       <div class="main wrapper flex gap-4">
                          

                          <div class="leftside flex">
                           <!-- email -->


                            <!-- <div class='form-row row my-2'>
                           <div class='col-sm-12  form-group required'>
                              <label class='control-label'><b>  Email </b></label> 
                              <input class='form-control' size='4' name="email" id="" type='email'  >

                           </div> 

                        </div>  -->

                        <!-- name on card -->

                        <!-- <div class='form-row row my-2'>
                           <div class='col-sm-12  form-group required'>
                              <label class='control-label'><b> Name on Card </b></label> <input name="name" 
                                 class='form-control' size='4' type='text'>
                           </div>


                        </div> -->

                        <!-- card number -->

                        <!-- <div class='form-row row my-2'>
                           <div class='col-sm-12 form-group card required'>
                              <label class='control-label'><b> Card Number </b></label> <input autocomplete='on'
                                 autocomplete='off' class='form-control card-number' size='20'
                                 type='text'>

                                          

                           </div>

                         
                        </div> -->

         <!--  <div class='form-row row my-2'>
                           <div class='col-xs-12 col-md-4 form-group cvc required'>
                              <label class='control-label'><b> CVC </b></label> <input autocomplete='off'
                                 class='form-control card-cvc' placeholder='ex. 311' size='4'
                                 type='text'>
                           </div>
                           <div class='col-xs-12 col-md-4 form-group expiration required'>
                              <label class='control-label'><b> Exp. Month </b></label> <input autocomplete='on'
                                 class='form-control card-expiry-month' placeholder='MM/Ex.  07' size='2'
                                 type='text'>
                           </div>
                           <div class='col-xs-12 col-md-4 form-group expiration required'>
                              <label class='control-label'><b> Exp. Year </b></label> <input autocomplete='on'
                                 class='form-control card-expiry-year' placeholder='YYYY/Ex. 2022' size='4'
                                 type='text'>
                           </div>
                        </div> -->



                       
 



                       </div>




                       <!-- right side -->



                       
                          
                       </div>



                        

                       

                           <input step="0.01" hidden type="number" name="percent" value="{{$percent}}">                   
                           <input hidden type="number" name="listing" value="{{$business_id}}">
                           
                                       
                                       

                        <!-- Shipping address  ends --> 




                        <div class="right flex flex-col">
                          


                          <div class='form-row row my-2'>
                           <div class='col-sm-12  form-group required'>
                              <label class='control-label'><b>  Amount(USD) </b> <small>5% + tax added</small></label> 
                              <input class='form-control' size='4' name="price" id="price" type='number' value="{{$amount}}" readonly >

                              <input class='form-control' size='4' name="amountReal" id="amountReal" type='number' value="{{$amountReal}}" readonly hidden>

                           </div> 

                        </div>  


                        <div class="d-flex flex-column  gap-3">

        <!-- Purpose -->
        <label class=" purpose">Purpose</label>

        <p id="purpose"></p>
    </div>

     <!-- total -->
      <h2 class="py-2 " style="font-size:18px;">Total: {{$amountReal}}</h2>




                         <div class="privacy-wrp py-2">
                                            
                                                <input type="checkbox" required="" id="AND">
                                                <label for="AND" class="allterms d-inline"> 
                                                    <p class="d-inline small" style="font-size: 12px;">I HAVE READ AND AGREE TO THE WEBSITE <a class="text-light" href="#" disbaled> TERMS AND CONDITIONS</a></p>
                                                </label>  </div>


                        <div class="row">
                           <div class="col-sm-12 text-center">
                              <button id ="" class=" font-weight-bold btn  m-auto btn-lg btn-block" type="submit" style="background-color: rgb(22 101 52); color:white;">Pay <span id="paynow"></span><span id="stripBtn"></span></button>
                           </div>
                        </div>
                       </div> 
                        

                     

                        
                        


                       

                     </form>


                  </div>
               </div>
            </div>

            

         </div>
      </div>

	
</div>








<script type="text/javascript" src="https://js.stripe.com/v2/"></script>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
    

   <script type="text/javascript">


      $(function() {
    var $form = $(".require-validation");
    $('form.require-validation').bind('submit', function(e) {
        var $form = $(".require-validation"),
            inputSelector = ['input[type=email]', 'input[type=password]',
                'input[type=text]', 'input[type=file]',
                'textarea'
            ].join(', '),
            $inputs = $form.find('.required').find(inputSelector),
            $errorMessage = $form.find('div.error'),
            valid = true;
        $errorMessage.addClass('hide');
        $('.has-error').removeClass('has-error');
        $inputs.each(function(i, el) {
            var $input = $(el);
            if ($input.val() === '') {
                $input.parent().addClass('has-error');
                $errorMessage.removeClass('hide');
                e.preventDefault();
            }
        });
        if (!$form.data('cc-on-file')) {
            e.preventDefault();
            Stripe.setPublishableKey($form.data('stripe-publishable-key'));
            Stripe.createToken({
                number: $('.card-number').val(),
                cvc: $('.card-cvc').val(),
                exp_month: $('.card-expiry-month').val(),
                exp_year: $('.card-expiry-year').val()
            }, stripeResponseHandler);
        }
    });
    function stripeResponseHandler(status, response) {
        if (response.error) {
            $('.error')
                .removeClass('collapse')
                .find('.alert')
                .text(response.error.message);
        } else {
            /* token contains id, last4, and card type */
            var token = response['id'];
            $form.find('input[type=text]').empty();
            $form.append("<input type='hidden' name='stripeToken' value='" + token + "'/>");
            $form.get(0).submit();
        }
    }
});

      function popupClose() {
            $('.success_message').css('display', 'none');
        }
   </script>

</body>

</html>
