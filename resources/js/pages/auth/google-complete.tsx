import { Form, Head, setLayoutProps } from '@inertiajs/react';
import GoogleLoginController from '@/actions/App/Http/Controllers/Auth/GoogleLoginController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    name: string;
    email: string;
    trialDays: number;
};

export default function GoogleComplete({ name, email, trialDays }: Props) {
    setLayoutProps({
        title: 'One last step',
        description: `Signed in as ${email}. Name your business to start your ${trialDays}-day free trial.`,
    });

    return (
        <>
            <Head title="Complete your registration" />

            <Form {...GoogleLoginController.complete.form()}>
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field>
                            <FieldLabel htmlFor="business_name">
                                Business name
                            </FieldLabel>
                            <Input
                                id="business_name"
                                name="business_name"
                                required
                                autoFocus
                                autoComplete="organization"
                                placeholder="e.g. Atlas Store"
                            />
                            <FieldDescription>
                                Shown to your team — you can change it later.
                            </FieldDescription>
                            <InputError message={errors.business_name} />
                        </Field>

                        <Field>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Start free trial
                                {name ? `, ${name.split(' ')[0]}` : ''}
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}
