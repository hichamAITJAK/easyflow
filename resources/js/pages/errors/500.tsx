import ErrorPage from './error-page';

export default function Error500() {
    return (
        <ErrorPage
            status={500}
            title="Something went wrong"
            message="An unexpected error occurred on our end. Please try again shortly."
        />
    );
}
