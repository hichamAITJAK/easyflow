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

import { useTranslation } from '@/hooks/use-translation';
type Props = {
    name: string;
    email: string;
};

export default function GoogleComplete({ name, email }: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('One last step'),
        description: t('Signed in as :email. Name your business to get started.', { email }),
    });

    return (
        <>
            <Head title={t('Complete your registration')} />

            <Form {...GoogleLoginController.complete.form()}>
                {({ processing, errors }) => (
                    <FieldGroup>
                        <Field>
                            <FieldLabel htmlFor="business_name">
                                {t('Business name')}
                            </FieldLabel>
                            <Input
                                id="business_name"
                                name="business_name"
                                required
                                autoFocus
                                autoComplete="organization"
                                placeholder={t('e.g. Atlas Store')}
                            />
                            <FieldDescription>
                                {t('Shown to your team — you can change it later.')}
                            </FieldDescription>
                            <InputError message={errors.business_name} />
                        </Field>

                        <Field>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Create business
                                {name ? `, ${name.split(' ')[0]}` : ''}
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}
