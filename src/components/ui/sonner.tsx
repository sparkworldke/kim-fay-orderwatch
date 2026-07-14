import { Toaster as Sonner } from "sonner";

type ToasterProps = React.ComponentProps<typeof Sonner>;

const Toaster = ({ ...props }: ToasterProps) => {
  return (
    <Sonner
      className="toaster group z-[100]"
      closeButton
      toastOptions={{
        duration: 6000,
        classNames: {
          // Do not force bg-background here — it overrides richColors error/success tints
          // and can make auth error toasts look invisible against the page.
          toast:
            "group toast group-[.toaster]:border-border group-[.toaster]:shadow-lg",
          description: "group-[.toast]:text-muted-foreground",
          actionButton: "group-[.toast]:bg-primary group-[.toast]:text-primary-foreground",
          cancelButton: "group-[.toast]:bg-muted group-[.toast]:text-muted-foreground",
          error: "group-[.toaster]:border-red-500/40",
          success: "group-[.toaster]:border-green-500/40",
        },
      }}
      {...props}
    />
  );
};

export { Toaster };
